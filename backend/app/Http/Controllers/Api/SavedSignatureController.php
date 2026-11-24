<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SAVED SIGNATURE AND INITIALS (2026-11-06).
 *
 * Anthony: "where you can save a signature to be reused that is authorized by the user when
 * signing documents/forms". Everyone may save one (and initials); nobody else can see,
 * set or apply it.
 *
 *   GET    /me/saved-signatures               mine (signature + initials), images included
 *   POST   /me/saved-signatures               save { kind, image, agree } - replaces the active one
 *   DELETE /me/saved-signatures/{kind}        stop using it (kept as evidence, never shown again)
 *   POST   /me/saved-signatures/{kind}/use    apply it to ONE document: { context } -> { image, use_id }
 *
 * NEVER APPLIED AUTOMATICALLY. The pads (kt-signature-pad.js) offer "Use my saved
 * signature" as a tap, with the consent line beside it; each tap is one `use` row holding
 * the exact words, the time, IP and device. A document's own signing endpoint still
 * receives an ordinary image, so every existing record and PDF stays as it is.
 *
 * PERSONAL ONLY. A super admin viewing as somebody gets nothing back and cannot save or
 * apply: a signature applied by someone else is not that person's signature.
 */
final class SavedSignatureController extends Controller
{
    public const KINDS = ['signature', 'initials'];

    public const AUTHORIZE_TEXT = [
        'signature' => 'I authorize KiddieTrac to keep this signature for me. It will be applied to a document only when I tap "Use my saved signature" on that document, and doing so has the same effect as signing it by hand. I can remove it at any time in My settings.',
        'initials' => 'I authorize KiddieTrac to keep these initials for me. They will be applied to a document only when I tap "Use my saved initials" on that document, and doing so has the same effect as initialling it by hand. I can remove them at any time in My settings.',
    ];

    public const USE_TEXT = [
        'signature' => 'By tapping, I sign this document with my saved signature.',
        'initials' => 'By tapping, I initial this document with my saved initials.',
    ];

    public function mine(Request $request): JsonResponse
    {
        if ($this->viewingAs($request)) {
            return response()->json(['signature' => null, 'initials' => null, 'viewing_as' => true]);
        }
        $uid = (int) $request->user()->id;
        $out = ['viewing_as' => false, 'authorize_text' => self::AUTHORIZE_TEXT, 'use_text' => self::USE_TEXT];
        foreach (self::KINDS as $k) {
            $r = $this->active($uid, $k);
            $out[$k] = $r ? ['image' => $r->image, 'saved_at' => $r->authorized_at] : null;
        }
        $out['uses'] = DB::table('user_signature_uses')->where('user_id', $uid)->count();

        return response()->json($out);
    }

    public function save(Request $request): JsonResponse
    {
        abort_if($this->viewingAs($request), 403, 'Only the person themselves can save their signature, not someone viewing as them.');
        $data = $request->validate([
            'kind' => ['required', 'in:signature,initials'],
            'image' => ['required', 'string', 'max:400000'],
            'agree' => ['accepted'],
        ]);
        $image = $this->checkImage($data['image']);
        $uid = (int) $request->user()->id;
        $kind = $data['kind'];

        $id = DB::transaction(function () use ($uid, $kind, $image, $request) {
            DB::table('user_signatures')->where('user_id', $uid)->where('kind', $kind)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            return DB::table('user_signatures')->insertGetId([
                'user_id' => $uid,
                'kind' => $kind,
                'image' => $image,
                'image_hash' => hash('sha256', $image),
                'authorization_text' => self::AUTHORIZE_TEXT[$kind],
                'authorized_at' => now(),
                'authorized_ip' => $request->ip(),
                'authorized_ua' => mb_substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->audit($request, 'saved_signature.saved', $id, 'Saved their ' . ($kind === 'initials' ? 'initials' : 'signature') . ' for re-use');

        return response()->json(['ok' => true, $kind => ['image' => $image, 'saved_at' => now()->toDateTimeString()]]);
    }

    public function remove(Request $request, string $kind): JsonResponse
    {
        abort_if($this->viewingAs($request), 403, 'Only the person themselves can remove their saved signature.');
        abort_unless(in_array($kind, self::KINDS, true), 404);
        $uid = (int) $request->user()->id;
        $r = $this->active($uid, $kind);
        if ($r) {
            DB::table('user_signatures')->where('id', $r->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
            $this->audit($request, 'saved_signature.removed', (int) $r->id, 'Removed their saved ' . ($kind === 'initials' ? 'initials' : 'signature'));
        }

        return response()->json(['ok' => true, $kind => null]);
    }

    public function apply(Request $request, string $kind): JsonResponse
    {
        abort_if($this->viewingAs($request), 403, 'A saved signature can only be applied by its owner.');
        abort_unless(in_array($kind, self::KINDS, true), 404);
        $data = $request->validate(['context' => ['nullable', 'string', 'max:120']]);
        $uid = (int) $request->user()->id;
        $r = $this->active($uid, $kind);
        if (! $r) {
            return response()->json(['message' => 'You have no saved ' . ($kind === 'initials' ? 'initials' : 'signature') . ' yet. Please draw it instead.'], 404);
        }
        $useId = DB::table('user_signature_uses')->insertGetId([
            'user_id' => $uid,
            'user_signature_id' => (int) $r->id,
            'kind' => $kind,
            'context' => isset($data['context']) ? mb_substr(preg_replace('/[^A-Za-z0-9:_\-.\/]/', '', (string) $data['context']), 0, 120) : null,
            'consent_text' => self::USE_TEXT[$kind],
            'ip' => $request->ip(),
            'ua' => mb_substr((string) $request->userAgent(), 0, 255),
            'used_at' => now(),
        ]);

        return response()->json(['image' => $r->image, 'use_id' => $useId]);
    }

    /* ── helpers ───────────────────────────────────────────────────────────────────────── */

    private function active(int $uid, string $kind): ?object
    {
        return DB::table('user_signatures')->where('user_id', $uid)->where('kind', $kind)
            ->whereNull('revoked_at')->orderByDesc('id')->first(['id', 'image', 'authorized_at']);
    }

    /** A real PNG of a sane size, or a 422. Anything else could be a pixel, a photo or a script. */
    private function checkImage(string $dataUrl): string
    {
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            abort(422, 'That signature could not be read. Please draw it again.');
        }
        $bin = base64_decode($m[1], true);
        $size = ($bin !== false && strlen($bin) > 64 && strncmp($bin, "\x89PNG", 4) === 0) ? @getimagesizefromstring($bin) : false;
        if (! $size || ($size['mime'] ?? '') !== 'image/png' || $size[0] < 40 || $size[1] < 20 || $size[0] > 4000 || $size[1] > 2000) {
            abort(422, 'That signature could not be read. Please draw it again.');
        }

        return $dataUrl;
    }

    private function viewingAs(Request $request): bool
    {
        $pat = method_exists($request->user(), 'currentAccessToken') ? $request->user()->currentAccessToken() : null;

        return $pat && isset($pat->name) && str_starts_with((string) $pat->name, 'impersonation:');
    }

    private function audit(Request $request, string $action, int $id, string $summary): void
    {
        try {
            Audit::write([
                'user_id' => (int) $request->user()->id,
                'agency_id' => null,   // a person's own record, not any agency's
                'action' => $action,
                'entity_type' => 'user_signature',
                'entity_id' => $id,
                'payload' => json_encode(['summary' => $summary]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
