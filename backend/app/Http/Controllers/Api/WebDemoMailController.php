<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PublicDemo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The website demo phone's Mail app (2026-10-31, App\Support\WebDemoMail): a demo person reads ONLY their own captured
 * mail. Anyone who is not a public-demo person gets 404. GET /demo/mail, GET /demo/mail/{id}, GET /demo/mail/{id}/files/{n}.
 */
class WebDemoMailController extends Controller
{
    private function me(Request $r): ?int
    {
        $u = $r->user();
        return ($u && PublicDemo::isUser((int) $u->id)) ? (int) $u->id : null;
    }

    public function index(Request $r): JsonResponse
    {
        $uid = $this->me($r);
        if (! $uid) return response()->json(['message' => 'Not found.'], 404);
        $rows = DB::table('web_demo_mail')->where('user_id', $uid)->orderByDesc('id')->limit(60)
            ->get(['id', 'from_name', 'from_email', 'subject', 'text', 'attachments', 'read_at', 'created_at']);
        $items = $rows->map(function ($m) {
            $att = json_decode((string) $m->attachments, true) ?: [];
            $prev = trim(preg_replace('/\s+/', ' ', (string) $m->text));
            return ['id' => (int) $m->id, 'from' => $m->from_name ?: $m->from_email, 'from_email' => $m->from_email,
                'subject' => $m->subject, 'preview' => mb_substr($prev, 0, 140), 'files' => count($att),
                'unread' => $m->read_at === null, 'at' => \Illuminate\Support\Carbon::parse($m->created_at)->toIso8601String()];
        })->values();
        return response()->json(['items' => $items, 'unread' => $rows->whereNull('read_at')->count(),
            'last' => (int) ($rows->max('id') ?? 0)]);
    }

    public function show(Request $r, int $id): JsonResponse
    {
        $uid = $this->me($r);
        $m = $uid ? DB::table('web_demo_mail')->where('id', $id)->where('user_id', $uid)->first() : null;
        if (! $m) return response()->json(['message' => 'Not found.'], 404);
        if ($m->read_at === null) DB::table('web_demo_mail')->where('id', $id)->update(['read_at' => now()]);
        $att = array_map(fn ($a) => ['n' => $a['n'], 'name' => $a['name'], 'mime' => $a['mime'], 'size' => $a['size']],
            json_decode((string) $m->attachments, true) ?: []);
        return response()->json(['id' => (int) $m->id, 'from' => $m->from_name ?: $m->from_email, 'from_email' => $m->from_email,
            'subject' => $m->subject, 'html' => $m->html, 'text' => $m->text, 'files' => $att,
            'at' => \Illuminate\Support\Carbon::parse($m->created_at)->toIso8601String()]);
    }

    public function file(Request $r, int $id, int $n)
    {
        $uid = $this->me($r);
        $m = $uid ? DB::table('web_demo_mail')->where('id', $id)->where('user_id', $uid)->first() : null;
        if (! $m) return response()->json(['message' => 'Not found.'], 404);
        foreach (json_decode((string) $m->attachments, true) ?: [] as $a) {
            if ((int) $a['n'] === $n && \Illuminate\Support\Facades\Storage::disk('local')->exists($a['path'])) {
                return \App\Support\FileResponse::download($a['path'], $a['name'], ['Content-Type' => $a['mime']], 'local', true);
            }
        }
        return response()->json(['message' => 'Not found.'], 404);
    }
}
