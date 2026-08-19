<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Subscribers captured by the public website.
 *
 * The website is a platform-level thing — one site, not one per agency — so these are
 * restricted to platform administrators, the same as the Website screen itself.
 *
 * Unsubscribing never deletes the row. It stamps a time, because "when did this person
 * unsubscribe" is the question that actually gets asked, and a deleted row cannot answer
 * it. Deleting is a separate, deliberate action for genuine erasure requests.
 */
class SiteSubscriberController extends Controller
{
    private function assertPlatform(Request $request): void
    {
        $ok = DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('active', 1)->where('role', 'platform_admin')->exists();
        abort_unless($ok, 403, 'Platform administrator access required.');
    }

    public function index(Request $request): JsonResponse
    {
        $this->assertPlatform($request);

        $status = strtolower((string) $request->query('status', 'active'));
        $q = DB::table('site_subscribers');

        if ($status === 'unsubscribed') {
            $q->whereNotNull('unsubscribed_at')->orderByDesc('unsubscribed_at');
        } elseif ($status === 'all') {
            $q->orderByDesc('subscribed_at');
        } else {
            $q->whereNull('unsubscribed_at')->orderByDesc('subscribed_at');
        }

        if ($term = trim((string) $request->query('q', ''))) {
            $q->where(function ($w) use ($term) {
                $w->where('email', 'like', '%' . $term . '%')
                  ->orWhere('name', 'like', '%' . $term . '%')
                  ->orWhere('agency_name', 'like', '%' . $term . '%');
            });
        }

        $rows = $q->limit(2000)->get();

        return response()->json([
            'data' => $rows,
            'counts' => [
                'active' => DB::table('site_subscribers')->whereNull('unsubscribed_at')->count(),
                'unsubscribed' => DB::table('site_subscribers')->whereNotNull('unsubscribed_at')->count(),
            ],
        ]);
    }

    /** Record an unsubscribe made through the portal on somebody's behalf. */
    public function unsubscribe(Request $request, int $id): JsonResponse
    {
        $this->assertPlatform($request);
        $row = DB::table('site_subscribers')->where('id', $id)->first();
        abort_unless($row, 404);

        if ($row->unsubscribed_at) {
            return response()->json(['ok' => true, 'already' => true]);
        }

        $note = trim((string) $request->input('note', ''));
        DB::table('site_subscribers')->where('id', $id)->update([
            'unsubscribed_at' => now(),
            'unsubscribed_by' => 'admin',
            'unsubscribe_note' => $note !== '' ? mb_substr($note, 0, 190) : null,
            'updated_at' => now(),
        ]);

        // The mail layer reads the file list, so it has to agree with the table.
        MarketingSiteController::suppressEmail((string) $row->email);

        // This is the case that most needs a confirmation: somebody who asked by phone or
        // email never saw the web page, so without this they have nothing showing it was
        // done. Guarded — a failed receipt must not undo the removal.
        try {
            \Illuminate\Support\Facades\Mail::to($row->email)
                ->send(new \App\Mail\SubscriberUnsubscribed(
                    (string) $row->email, (string) ($row->name ?? ''), 'admin'
                ));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Unsubscribe confirmation failed', [
                'email' => $row->email, 'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Undo an unsubscribe. Only meaningful when somebody asks to come back, so the
     * previous unsubscribe date is cleared rather than kept — consent is current or it is
     * not, and a stale timestamp on an active subscriber reads as a contradiction.
     */
    public function resubscribe(Request $request, int $id): JsonResponse
    {
        $this->assertPlatform($request);
        $row = DB::table('site_subscribers')->where('id', $id)->first();
        abort_unless($row, 404);

        DB::table('site_subscribers')->where('id', $id)->update([
            'unsubscribed_at' => null,
            'unsubscribed_by' => null,
            'unsubscribe_note' => null,
            'subscribed_at' => now(),
            'updated_at' => now(),
        ]);
        MarketingSiteController::unsuppressEmail((string) $row->email);

        return response()->json(['ok' => true]);
    }

    /** Erasure. Separate from unsubscribing, and it takes the suppression with it. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->assertPlatform($request);
        $row = DB::table('site_subscribers')->where('id', $id)->first();
        abort_unless($row, 404);

        DB::table('site_subscribers')->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    /** Add somebody by hand — a signup taken over the phone, or an import. */
    public function store(Request $request): JsonResponse
    {
        $this->assertPlatform($request);
        $data = $request->validate([
            'email' => 'required|email|max:190',
            'name' => 'nullable|string|max:120',
            'agency_name' => 'nullable|string|max:160',
        ]);
        $email = strtolower(trim($data['email']));

        $existing = DB::table('site_subscribers')->where('email', $email)->first();
        if ($existing) {
            // Adding an address that already unsubscribed is a re-subscribe, not a
            // duplicate — and silently ignoring it would look like the add failed.
            DB::table('site_subscribers')->where('id', $existing->id)->update([
                'unsubscribed_at' => null, 'unsubscribed_by' => null, 'unsubscribe_note' => null,
                'subscribed_at' => now(), 'updated_at' => now(),
            ]);
            MarketingSiteController::unsuppressEmail($email);

            return response()->json(['ok' => true, 'id' => $existing->id, 'resubscribed' => true]);
        }

        $id = DB::table('site_subscribers')->insertGetId([
            'email' => $email,
            'name' => $data['name'] ?? null,
            'agency_name' => $data['agency_name'] ?? null,
            'source' => 'added-by-admin',
            'subscribed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }
}
