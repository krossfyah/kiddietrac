<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * v22p6 — Emergency cards.
 *
 * Renders a print-optimised one-page-per-child emergency record. Returns
 * HTML (not PDF) — the staff member uses browser Print / Save as PDF.
 * dompdf is not installed in vendor; rather than ship a dependency-heavy
 * install on shared hosting, the HTML is laid out with @page rules and
 * page-break-after for batch printing.
 *
 * Routes (director-scoped):
 *   GET /api/v1/director/children/{child}/emergency-card
 *   GET /api/v1/director/rooms/{room}/emergency-cards
 */
final class EmergencyCardController extends Controller
{
    use ResolvesCentreContext;

    public function forChild(Request $request, int $childId): SymfonyResponse
    {
        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        if (! $child) {
            return response('Not found', 404);
        }

        $family = DB::table('families')->where('id', $child->family_id)->first();
        if (! $family || ! $this->authorizeCentreAccess($request->user(), (int) $family->centre_id)) {
            return response('Forbidden', 403);
        }

        return $this->renderChild($child, $family);
    }

    /**
     * GET /director/children/{child}/emergency-card/print-link
     *
     * A five-minute signed link to the card, for printing from the phone app.
     *
     * The app's web view does not implement window.print() — neither Android's nor
     * iOS's does; a host app has to drive the platform print service itself, and this
     * one cannot without a rebuild. So the card is handed to the device's REAL browser
     * through the Capacitor Browser plugin, where Print works normally. That browser
     * carries no session, which is what this link is for.
     *
     * The exposure is real and deliberately small. The card holds a child's allergies,
     * their guardians' numbers and where they are, so:
     *   - minting runs the SAME authorisation the card itself does, on the same
     *     centre check, so nobody can mint a link to a child they cannot already read;
     *   - the link lives five minutes;
     *   - both the mint and every use of it are audited, so an unexpected read is
     *     visible rather than silent.
     * Signed URLs are Laravel's own — the signature covers the child id and the expiry,
     * and the `signed` middleware rejects a tampered or stale one before this class is
     * reached.
     */
    public function printLink(Request $request, int $childId): JsonResponse
    {
        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        if (! $child) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $family = DB::table('families')->where('id', $child->family_id)->first();
        if (! $family || ! $this->authorizeCentreAccess($request->user(), (int) $family->centre_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $minutes = 5;
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'emergency.card.signed',
            now()->addMinutes($minutes),
            ['child' => $childId]
        );

        \App\Support\Audit::write([
            'user_id' => $request->user()->id ?? null,
            'agency_id' => \App\Support\AuditScope::resolve((int) ($request->user()->id ?? 0), $request),
            'action' => 'emergency_card.print_link_issued',
            'entity_type' => 'child',
            'entity_id' => $childId,
            'payload' => json_encode([
                'summary' => 'Issued a '.$minutes.'-minute printable link for an emergency card',
                'child_id' => $childId,
                'expires_in_minutes' => $minutes,
            ]),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);

        return response()->json(['url' => $url, 'expires_in_minutes' => $minutes]);
    }

    /**
     * GET /print/emergency-card/{child} — signed, no session.
     *
     * Reached only with a valid signature; the `signed` middleware has already rejected
     * anything tampered with or expired. Audited on every read, with no user id because
     * there is no session — the mint row above names who created the link.
     */
    public function signedForChild(Request $request, int $childId): SymfonyResponse
    {
        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        if (! $child) {
            return response('Not found', 404);
        }

        $family = DB::table('families')->where('id', $child->family_id)->first();
        if (! $family) {
            return response('Not found', 404);
        }

        \App\Support\Audit::write([
            'user_id' => null,
            'agency_id' => null,
            'action' => 'emergency_card.printed',
            'entity_type' => 'child',
            'entity_id' => $childId,
            'payload' => json_encode([
                'summary' => 'Emergency card opened through a signed printable link',
                'child_id' => $childId,
            ]),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);

        return $this->renderChild($child, $family);
    }

    /** The render both entry points share, so the two can never drift apart. */
    private function renderChild(object $child, object $family): SymfonyResponse
    {
        $centre = DB::table('centres')->where('id', $family->centre_id)->first();
        $card = $this->buildCardData($child, $family, $centre);
        $html = $this->buildPage([$card], $centre);

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function forRoom(Request $request, int $roomId): SymfonyResponse
    {
        $room = DB::table('rooms')->where('id', $roomId)->first();
        if (! $room) {
            return response('Not found', 404);
        }
        if (! $this->authorizeCentreAccess($request->user(), (int) $room->centre_id)) {
            return response('Forbidden', 403);
        }

        $centre = DB::table('centres')->where('id', $room->centre_id)->first();

        // All currently-enrolled children whose active enrollment is in this room.
        $childIds = DB::table('enrollments')
            ->where('room_id', $roomId)
            ->whereNull('end_date')
            ->pluck('child_id')
            ->all();

        $cards = [];
        if (! empty($childIds)) {
            $children = DB::table('children')
                ->whereIn('id', $childIds)
                ->whereNull('deleted_at')
                ->orderBy('first_name')
                ->get();
            foreach ($children as $child) {
                $family = DB::table('families')->where('id', $child->family_id)->first();
                if (! $family) continue;
                $cards[] = $this->buildCardData($child, $family, $centre, $room);
            }
        }

        $html = $this->buildPage($cards, $centre, $room);
        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    // ───────────────────────────────────────────────────────────────────────

    private function buildCardData(object $child, object $family, ?object $centre, ?object $room = null): array
    {
        if (! $room) {
            $enrollment = DB::table('enrollments')
                ->where('child_id', $child->id)
                ->whereNull('end_date')
                ->first();
            $room = $enrollment ? DB::table('rooms')->where('id', $enrollment->room_id)->first() : null;
        }

        $healthFlags = DB::table('child_health_flags')
            ->where('child_id', $child->id)
            ->where('active', true)
            ->get();

        $guardians = DB::table('guardians')
            ->join('users', 'users.id', '=', 'guardians.user_id')
            ->where('guardians.family_id', $child->family_id)
            ->orderByDesc('guardians.is_primary')
            ->get([
                'users.first_name', 'users.last_name', 'users.phone', 'users.email',
                'users.photo_url', 'users.sex',
                'guardians.relationship', 'guardians.is_primary', 'guardians.can_pickup',
            ]);

        $medications = DB::table('medications')
            ->where('child_id', $child->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->get(['name', 'strength', 'dosage', 'frequency', 'special_instructions',
                   'route', 'storage_location', 'requires_refrigeration', 'prescribing_physician']);

        /* The contacts to ring when the guardians cannot be reached. This table exists
           for exactly this card and the card never read it. */
        $emergencyContacts = Schema::hasTable('emergency_contacts')
            ? DB::table('emergency_contacts')->where('family_id', $child->family_id)
                ->get(['name', 'relationship', 'phone', 'alt_phone', 'can_pickup', 'notes'])
            : collect();

        /* Who is caring for this child, and who has them TODAY. With a week that can be
           split between providers, "their educator" is a question with a different answer
           depending on the day — and an emergency is always on a particular day. */
        $todayRoomId = \App\Support\CareSchedule::roomToday((int) $child->id)
            ?: ($room->id ?? null);
        $todayRoom = $todayRoomId ? DB::table('rooms')->where('id', $todayRoomId)->first() : $room;
        $todayCentre = $todayRoom
            ? DB::table('centres')->where('id', $todayRoom->centre_id)->first()
            : $centre;

        $educators = $this->providerFor($todayRoomId, $todayCentre);

        $weekSummary = \App\Support\CareSchedule::summary((int) $child->id);

        $age = '';
        if ($child->date_of_birth) {
            $dob = new \DateTime($child->date_of_birth);
            $now = new \DateTime();
            $diff = $now->diff($dob);
            $age = ($diff->y > 0 ? $diff->y.'y ' : '').$diff->m.'m';
        }

        return [
            'child' => $child,
            'family' => $family,
            'centre' => $todayCentre ?: $centre,
            'room' => $todayRoom ?: $room,
            'age' => $age,
            'health_flags' => $healthFlags,
            'guardians' => $guardians,
            'medications' => $medications,
            'emergency_contacts' => $emergencyContacts,
            'educators' => $educators,
            'week_summary' => $weekSummary,
        ];
    }

    private function buildPage(array $cards, ?object $centre, ?object $room = null): string
    {
        $titleSuffix = $room ? ' — Room: '.$this->esc($room->name) : '';
        $title = 'Emergency Cards'.$titleSuffix;
        $generatedAt = now()->toDayDateTimeString();

        $cardsHtml = '';
        if (empty($cards)) {
            $cardsHtml = '<div class="empty">No enrolled children to render.</div>';
        } else {
            foreach ($cards as $i => $c) {
                $cardsHtml .= $this->renderCard($c, $i + 1, count($cards));
            }
        }

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<!-- Without this a phone lays the page out at ~980px and scales it down, which is why
     the two-column people grid squeezed "THEIR PROVIDER TODAY" onto two colliding
     lines. Print is unaffected: @page owns the printed layout. -->
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>
  :root { --kt-blue: #1F6080; --kt-red: #DC2626; --kt-amber: #F59E0B; --kt-faint: #6B7280; --kt-border: #E5E7EB; }
  * { box-sizing: border-box; }
  body { margin: 0; padding: 24px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #111827; background: #F6F8FA; }
  .toolbar { background: white; border: 1px solid var(--kt-border); border-radius: 12px; padding: 12px 18px; max-width: 800px; margin: 0 auto 18px; display: flex; justify-content: space-between; align-items: center; }
  .toolbar h1 { font-size: 16px; margin: 0; }
  .toolbar p  { font-size: 12px; margin: 2px 0 0; color: var(--kt-faint); }
  .btn { background: var(--kt-blue); color: white; padding: 8px 14px; border-radius: 8px; border: none; font-weight: 600; cursor: pointer; font-size: 13px; }
  .card { background: white; border: 1px solid var(--kt-border); border-radius: 14px; padding: 28px; max-width: 800px; margin: 0 auto 18px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
  .card-head { display: grid; grid-template-columns: 100px 1fr auto; gap: 18px; align-items: center; padding-bottom: 16px; border-bottom: 2px solid var(--kt-blue); margin-bottom: 16px; }
  .photo { width: 100px; height: 100px; border-radius: 12px; background: var(--kt-blue); color: white; font-size: 42px; font-weight: 800; display: flex; align-items: center; justify-content: center; overflow: hidden; }
  .photo img { width: 100%; height: 100%; object-fit: cover; }
  h2 { font-size: 22px; margin: 0 0 4px; }
  .meta { color: var(--kt-faint); font-size: 13px; }
  .agency-logo { text-align: right; font-size: 11px; color: var(--kt-faint); }
  .agency-logo img { max-height: 50px; max-width: 110px; display: block; margin-left: auto; }
  .section { margin-bottom: 14px; }
  .section h3 { font-size: 12px; letter-spacing: 1px; text-transform: uppercase; color: var(--kt-faint); margin: 0 0 6px; font-weight: 700; }
  .alert-list .alert { background: #FEF2F2; border-left: 4px solid var(--kt-red); padding: 6px 10px; margin-bottom: 4px; font-size: 13px; color: #7F1D1D; border-radius: 4px; }
  .info-row { display: grid; grid-template-columns: 150px 1fr; gap: 12px; padding: 6px 0; border-bottom: 1px solid #F3F4F6; font-size: 13px; }
  .info-row .lbl { color: var(--kt-faint); font-weight: 600; font-size: 12px; }
  .guardians table, .meds table { width: 100%; border-collapse: collapse; font-size: 12px; }
  .guardians th, .meds th { background: #F9FAFB; padding: 6px 8px; text-align: left; font-size: 11px; letter-spacing: 0.5px; color: var(--kt-faint); border-bottom: 1px solid var(--kt-border); }
  .guardians td, .meds td { padding: 6px 8px; border-bottom: 1px solid #F3F4F6; }
  .badge { background: var(--kt-blue); color: white; padding: 2px 6px; border-radius: 999px; font-size: 10px; font-weight: 700; }
  .badge-no-pickup { background: var(--kt-red); }
  .footer-stamp { display: flex; justify-content: space-between; padding-top: 12px; font-size: 11px; color: var(--kt-faint); border-top: 1px solid var(--kt-border); margin-top: 14px; }
  .empty { text-align: center; padding: 40px; color: var(--kt-faint); }

  /* ── Added 2026-08-27 ────────────────────────────────────────────────────
     The card was text on white and read as a form. It is used standing up, at a
     door, in a hurry — so faces are large, the things that can hurt a child are
     the loudest element on the page, and every phone number is bold. */
  .av { border-radius: 50%; object-fit: cover; display: inline-flex; flex: 0 0 auto;
        align-items: center; justify-content: center; background: #E4EEF2; }
  .av-i { background: var(--kt-blue); color: #fff; font-weight: 800; letter-spacing: .5px; }
  .photo { border-radius: 50%; }

  .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
  .people { display: flex; flex-direction: column; gap: 9px; }
  .person { display: flex; align-items: center; gap: 10px; }
  .person-t { min-width: 0; }
  .person-n { font-size: 14px; font-weight: 700; color: #0F172A; }
  .person-s { font-size: 12.5px; color: var(--kt-faint); }
  .week { margin-top: 9px; font-size: 12px; color: var(--kt-blue); font-weight: 700;
          background: #F0F7FA; border-radius: 8px; padding: 6px 9px; }

  /* Allergies are the reason this card exists — bigger and louder than anything else. */
  .alert-list .alert { background: #FEF2F2; border-left: 5px solid var(--kt-red);
        padding: 9px 12px; margin-bottom: 6px; font-size: 14.5px; color: #7F1D1D;
        border-radius: 6px; line-height: 1.45; }
  .alert-list .plan { margin-top: 4px; font-size: 12.5px; color: #991B1B; font-weight: 600; }

  /* Empty is not the same as safe, and it must not look the same either. */
  .none-recorded { background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 8px;
        padding: 9px 12px; font-size: 13px; color: #92400E; line-height: 1.5; }
  .missing { color: #B45309; font-weight: 600; }

  .where-b { background: #F8FAFC; border: 1px solid var(--kt-border); border-radius: 10px;
        padding: 10px 12px; font-size: 13px; line-height: 1.55; }
  .where-n { font-weight: 800; font-size: 14px; color: #0F172A; }
  .sub { font-size: 11.5px; color: var(--kt-faint); }
  table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
  th { background: #F9FAFB; padding: 6px 8px; text-align: left; font-size: 11px;
       letter-spacing: .5px; color: var(--kt-faint); border-bottom: 1px solid var(--kt-border); }
  td { padding: 6px 8px; border-bottom: 1px solid #F3F4F6; vertical-align: top; }

  /* The photo lies on top of the emoji rather than instead of it — see avatarHtml().
     A failed load simply reveals the face underneath. */
  .av-e { position: relative; overflow: hidden; }
  .av-img { position: absolute; top: 0; left: 0; width: 100%; height: 100%;
        object-fit: cover; border-radius: 50%; }

  .tb-actions { display: flex; gap: 8px; align-items: center; flex: 0 0 auto; }
  .btn-ghost { background: #fff; color: var(--kt-blue); border: 1px solid var(--kt-border); }

  /* ── Phone (2026-09-15) ──────────────────────────────────────────────────
     The card was built for letter paper and a desktop, and on a 390px screen the
     fixed grids did the damage: a three-column head crushed the agency name against
     the child's details, and a 1fr 1fr people grid left each column ~170px wide, so
     every uppercase heading wrapped mid-phrase.

     The photo deliberately does NOT shrink. This card is read standing up, at a door,
     in a hurry — the face is the point. */
  @media screen and (max-width: 640px) {
    body { padding: 12px; }
    .toolbar { flex-direction: column; align-items: stretch; gap: 10px; }
    .tb-actions .btn { flex: 1; }
    .card { padding: 16px; }
    /* Two columns, and the agency/provider name drops to its own row underneath
       instead of fighting the name for space. */
    .card-head { grid-template-columns: 100px 1fr; gap: 14px; }
    .agency-logo { grid-column: 1 / -1; text-align: left; }
    .agency-logo img { margin-left: 0; }
    /* One column. This is the fix for the wrapped headings. */
    .cols { grid-template-columns: 1fr; gap: 16px; }
    .info-row { grid-template-columns: 1fr; gap: 2px; }
    .section h3 { letter-spacing: .5px; }
  }

  @page { size: letter; margin: 0.5in; }
  @media print {
    body { background: white; padding: 0; }
    .toolbar { display: none; }
    .card { box-shadow: none; border: 1px solid var(--kt-border); margin: 0; max-width: none; page-break-after: always; page-break-inside: avoid; }
    /* Printers strip background colour by default, which would turn the allergy
       block into plain text and lose the one visual cue that matters most. */
    .alert-list .alert, .none-recorded, .where-b, th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .section, .person, .alert-list { page-break-inside: avoid; }
    .card:last-child { page-break-after: auto; }
  }
</style>
</head>
<body>
  <div class="toolbar">
    <div class="tb-text">
      <h1>{$title}</h1>
      <p>Generated {$generatedAt} · Print or Save as PDF (⌘/Ctrl+P)</p>
    </div>
    <div class="tb-actions">
      <!-- The page is written into a window.open() by the portal, so on a desktop
           browser close() works. Inside the Android/iOS web view there is no browser
           chrome and close() is refused, so fall back to going back — which returns to
           the child record the card was opened from. Both attempts are cheap and one of
           them always applies; before this there was no way off the page at all. -->
      <button class="btn btn-ghost" onclick="try{window.close();}catch(e){}setTimeout(function(){if(!window.closed){history.back();}},150);">✕ Close</button>
      <button class="btn" onclick="window.print()">🖨 Print / Save PDF</button>
    </div>
  </div>
  {$cardsHtml}
</body>
</html>
HTML;
    }

    /**
     * One child, one card.
     *
     * Ordered by what a person needs in the order they need it: who this is, what could
     * harm them, who to ring, where they physically are. Identifiers and the home address
     * sit at the bottom — nobody looks up a postal code first.
     *
     * "Not recorded" is printed in amber wherever a safety field is empty, rather than an
     * em-dash. A dash reads as "nothing to worry about"; the truth is that nobody has been
     * asked, and on an allergy line those are very different statements.
     */
    private function renderCard(array $c, int $idx, int $total): string
    {
        $child = $c['child'];
        $family = $c['family'];
        $centre = $c['centre'];
        $room = $c['room'];

        $name = $this->esc($child->preferred_name ?: $child->first_name).' '.$this->esc($child->last_name);
        $age = $this->esc($c['age'] ?: '—');
        $dob = $this->esc($child->date_of_birth ?: '—');
        $gender = $this->esc($child->gender ? str_replace('_', ' ', $child->gender) : '—');
        $roomName = $room ? $this->esc($room->name) : '—';
        $familyName = $this->esc($family->family_name);
        $address = $this->esc(trim(($family->address_line1 ?? '').' '.($family->city ?? '').' '
            .($family->province ?? '').' '.($family->postal_code ?? '')));
        $hcLast4 = $this->esc($child->health_card_last4 ?: '');
        $hcDisplay = $hcLast4 ? "xxxx-xxxx-{$hcLast4}" : $this->missing('Not recorded');
        $doctor = $child->doctor_name ? $this->esc($child->doctor_name) : $this->missing('No doctor on file');
        $doctorPhone = $this->esc($child->doctor_phone ?: '');

        $photo = $this->avatarHtml($child->photo_url ?? null,
            trim(($child->preferred_name ?: $child->first_name).' '.($child->last_name ?? '')), 96,
            $child->sex ?? $child->gender ?? null, true);

        $agencyLogo = ($centre && $centre->logo_url)
            ? '<img src="'.$this->esc($centre->logo_url).'" alt="">' : '';
        $centreName = $centre ? $this->esc($centre->name) : '';

        // ── What could harm this child ─────────────────────────────────────
        $alerts = [];
        foreach ($c['health_flags'] as $hf) {
            $label = strtoupper($hf->flag_type ?? 'condition');
            $detail = $hf->notes ?? $hf->category ?? 'unspecified';
            $sev = $hf->severity ? ' ('.$this->esc($hf->severity).')' : '';
            $alerts[] = '<strong>'.$this->esc($label).':</strong> '.$this->esc($detail).$sev
                .($hf->action_plan ? '<div class="plan">'.$this->esc($hf->action_plan).'</div>' : '');
        }
        /* Also the child record's own columns. The card only ever read child_health_flags,
           which is empty for every child on the system — so allergies typed onto the child
           record never reached the card that exists to carry them. */
        foreach (['allergies' => 'ALLERGY', 'dietary_restrictions' => 'DIETARY',
                  'health_alerts' => 'HEALTH'] as $col => $label) {
            foreach ($this->listFrom($child->$col ?? null) as $item) {
                $alerts[] = '<strong>'.$label.':</strong> '.$this->esc($item);
            }
        }
        if ($child->medical_notes ?? null) {
            $alerts[] = '<strong>MEDICAL:</strong> '.$this->esc($child->medical_notes);
        }
        if ($child->dietary_notes ?? null) {
            $alerts[] = '<strong>DIETARY:</strong> '.$this->esc($child->dietary_notes);
        }

        $alertsHtml = '<div class="section"><h3>⚠️ Allergies, medical &amp; dietary</h3>';
        if ($alerts) {
            $alertsHtml .= '<div class="alert-list">';
            foreach ($alerts as $a) { $alertsHtml .= '<div class="alert">'.$a.'</div>'; }
            $alertsHtml .= '</div>';
        } else {
            $alertsHtml .= '<div class="none-recorded">Nothing has been recorded for this child. '
                .'That is not the same as having no allergies — confirm with the family.</div>';
        }
        $alertsHtml .= '</div>';

        // ── Who has this child ─────────────────────────────────────────────
        $eduHtml = '<div class="section"><h3>👩‍🏫 Their provider today</h3>';
        if (count($c['educators'])) {
            $eduHtml .= '<div class="people">';
            foreach ($c['educators'] as $e) {
                $en = trim(($e->first_name ?? '').' '.($e->last_name ?? ''));
                $eduHtml .= '<div class="person">'
                    .$this->avatarHtml($e->photo_url ?? null, $en, 48, $e->sex ?? null, false)
                    .'<div class="person-t"><div class="person-n">'.$this->esc($en).'</div>'
                    .'<div class="person-s">'.($e->phone ? $this->esc($e->phone) : 'No phone on file').'</div>'
                    .'</div></div>';
            }
            $eduHtml .= '</div>';
        } else {
            $eduHtml .= '<div class="none-recorded">No educator is assigned to '.$roomName.'.</div>';
        }
        $eduHtml .= '<div class="week">'.$this->esc($c['week_summary'] ?? '').'</div></div>';

        // ── Who to ring ────────────────────────────────────────────────────
        $guardiansHtml = '<div class="section"><h3>📞 Guardians</h3><div class="people">';
        if (count($c['guardians']) === 0) {
            $guardiansHtml .= '<div class="none-recorded">No guardians on record.</div>';
        } else {
            foreach ($c['guardians'] as $g) {
                $gn = trim(($g->first_name ?? '').' '.($g->last_name ?? ''));
                $tags = ($g->is_primary ? '<span class="badge">PRIMARY</span>' : '')
                    .($g->can_pickup ? '' : '<span class="badge badge-no-pickup">NO PICKUP</span>');
                $guardiansHtml .= '<div class="person">'
                    .$this->avatarHtml($g->photo_url ?? null, $gn, 48, $g->sex ?? null, false)
                    .'<div class="person-t">'
                        .'<div class="person-n">'.$this->esc($gn).' '.$tags.'</div>'
                        .'<div class="person-s">'.$this->esc($g->relationship ?: 'guardian').' · '
                            .'<strong>'.($g->phone ? $this->esc($g->phone) : 'no phone').'</strong></div>'
                    .'</div></div>';
            }
        }
        $guardiansHtml .= '</div></div>';

        $ecHtml = '';
        if (count($c['emergency_contacts'])) {
            $ecHtml = '<div class="section"><h3>🆘 If the guardians cannot be reached</h3><table><thead><tr>'
                .'<th>Name</th><th>Relationship</th><th>Phone</th><th>Pickup?</th></tr></thead><tbody>';
            foreach ($c['emergency_contacts'] as $e) {
                $ph = trim($this->esc($e->phone ?? '').($e->alt_phone ? ' / '.$this->esc($e->alt_phone) : ''));
                $ecHtml .= '<tr><td><strong>'.$this->esc($e->name ?? '').'</strong>'
                    .($e->notes ? '<div class="sub">'.$this->esc($e->notes).'</div>' : '').'</td>'
                    .'<td>'.$this->esc($e->relationship ?: '—').'</td>'
                    .'<td>'.($ph ?: '—').'</td>'
                    .'<td>'.($e->can_pickup ? '✓' : '<span class="badge badge-no-pickup">NO</span>').'</td></tr>';
            }
            $ecHtml .= '</tbody></table></div>';
        }

        // ── Medications ────────────────────────────────────────────────────
        $medsHtml = '';
        if (count($c['medications']) > 0) {
            $medsHtml = '<div class="section"><h3>💊 Active medications</h3><table><thead><tr>'
                .'<th>Medication</th><th>Dose</th><th>When</th><th>Instructions</th></tr></thead><tbody>';
            foreach ($c['medications'] as $m) {
                $dose = trim(($m->dosage ?? '').' '.($m->strength ?? ''));
                $store = trim(($m->storage_location ?? '').(($m->requires_refrigeration ?? false) ? ' · refrigerated' : ''));
                $medsHtml .= '<tr>'
                    .'<td><strong>'.$this->esc($m->name ?? '').'</strong>'
                        .($m->route ? '<div class="sub">'.$this->humanise($m->route).'</div>' : '').'</td>'
                    .'<td>'.$this->esc($dose ?: '—').'</td>'
                    .'<td>'.$this->humanise($m->frequency ?? '').'</td>'
                    .'<td>'.$this->esc($m->special_instructions ?? '')
                        .($store ? '<div class="sub">Kept: '.$this->esc($store).'</div>' : '').'</td>'
                    .'</tr>';
            }
            $medsHtml .= '</tbody></table></div>';
        }

        // ── Where the child physically is ──────────────────────────────────
        $centreAddr = $centre
            ? $this->esc(trim(($centre->address_line1 ?? '').' '.($centre->city ?? '').' '
                .($centre->province ?? '').' '.($centre->postal_code ?? ''))) : '';
        $centrePhone = $centre && $centre->phone ? $this->esc($centre->phone) : '';
        $whereHtml = '<div class="section where"><h3>📍 Where this child is</h3>'
            .'<div class="where-b"><div class="where-n">'.($centreName ?: '—').'</div>'
            .($centreAddr ? '<div>'.$centreAddr.'</div>' : '')
            .($centrePhone ? '<div><strong>'.$centrePhone.'</strong></div>' : '')
            .'<div class="sub">Room: '.$roomName.'</div></div></div>';

        $expected = trim(($child->expected_dropoff_time ?? '').' – '.($child->expected_pickup_time ?? ''), ' –');

        return <<<HTML
<div class="card">
  <div class="card-head">
    <div class="photo">{$photo}</div>
    <div>
      <h2>{$name}</h2>
      <div class="meta">{$age} · DOB {$dob} · {$gender}</div>
      <div class="meta">{$familyName} family</div>
    </div>
    <div class="agency-logo">
      {$agencyLogo}
      <div>{$centreName}</div>
    </div>
  </div>

  {$alertsHtml}

  <div class="cols">
    <div>{$eduHtml}{$whereHtml}</div>
    <div>{$guardiansHtml}</div>
  </div>

  {$ecHtml}
  {$medsHtml}

  <div class="section">
    <h3>Identifiers</h3>
    <div class="info-row"><span class="lbl">Health card</span><span>{$hcDisplay}</span></div>
    <div class="info-row"><span class="lbl">Doctor</span><span>{$doctor} {$doctorPhone}</span></div>
    <div class="info-row"><span class="lbl">Usual hours</span><span>{$expected}</span></div>
    <div class="info-row"><span class="lbl">Home address</span><span>{$address}</span></div>
  </div>

  <div class="footer-stamp">
    <span>Card {$idx} of {$total}</span>
    <span>Confidential — do not redistribute</span>
  </div>
</div>
HTML;
    }

    /** A photo when there is one, a face when there is not. Never an empty box. */
    private function avatarHtml(?string $url, string $name, int $size, $sex = null, bool $isChild = false): string
    {
        $n = trim($name);

        /* THE EMOJI IS ALWAYS DRAWN, AND THE PHOTO SITS ON TOP OF IT (2026-09-15).

           Two bugs in one line here. The photo used to REPLACE the emoji, so a broken
           image left a grey box with a torn-paper icon in it — which is exactly what an
           educator and a guardian looked like on the card, while the child beside them
           (who has no photo at all, so took the emoji path) came out fine.

           It was broken because the URL was emitted raw. users.photo_url holds a plain
           '/storage/avatars/....jpg' for 34 of the 64 people who have one, and /storage
           is closed on both hosts — it answers 403, deliberately, because protected media
           is only served through the signed /api/v1/media/f route. Every JSON response
           gets those paths rewritten on the way out by App\Http\Middleware\
           SignProtectedMedia, but that middleware returns early on anything that is not
           a JsonResponse, and this card is HTML. So the one page built to be read in an
           emergency was the one place the signing never reached.

           Signed here the same way SignedFormController already does it. The other 30
           photos are absolute http URLs (pravatar, from the demo seeder) and sign()
           returns anything it does not own unchanged, so they are untouched.

           Layering rather than replacing also covers what signing cannot: an expired
           link, a deleted file, a blocked third-party host. The face underneath is the
           same one kt-emoji-avatars.js draws on screen, so a failure degrades to the
           portrait the reader already associates with that person instead of to a
           broken-image glyph. No script and no onerror handler, so it survives print
           and any CSP. */
        $face = '<span class="av av-e" style="width:'.$size.'px;height:'.$size.'px;font-size:'
            .max(16, (int) round($size * 0.62)).'px;">'.$this->personEmoji($n, $sex, $isChild);

        if ($url) {
            $face .= '<img class="av-img" src="'
                .$this->esc((string) \App\Support\ProtectedMedia::sign($url)).'" alt="">';
        }

        return $face.'</span>';
    }

    /** as_needed -> As needed. Database vocabulary should not reach a printed card. */
    private function humanise(?string $v): string
    {
        $v = trim((string) $v);

        return $v === '' ? '—' : $this->esc(ucfirst(str_replace('_', ' ', $v)));
    }

    /**
     * The provider who has this child, rather than everyone rostered to the room.
     *
     * A home-childcare centre IS one provider, and the provider is the user whose email
     * matches `centres.email` — the same match the Daily Overview uses. Several educators
     * can hold a role at one centre (centre 14 has three), and listing them all under
     * "their provider today" reads as three people caring for the child.
     *
     * Falls back to the room's educators when no account matches the centre email: a
     * centre whose email does not line up is a data problem to notice, not a reason for
     * an emergency card to name nobody.
     */
    private function providerFor(?int $roomId, ?object $centre)
    {
        if (! $roomId) {
            return collect();
        }

        $educators = DB::table('educator_rooms as er')
            ->join('users as u', 'u.id', '=', 'er.user_id')
            ->where('er.room_id', $roomId)
            ->whereNull('u.deleted_at')
            ->where('u.status', 'active')
            ->get(['u.first_name', 'u.last_name', 'u.phone', 'u.photo_url', 'u.email', 'u.sex']);

        $centreEmail = strtolower(trim((string) ($centre->email ?? '')));
        if ($centreEmail !== '') {
            // Case-insensitively: they genuinely differ in the data.
            $just = $educators->filter(
                fn ($u) => strtolower(trim((string) $u->email)) === $centreEmail
            )->values();
            if ($just->count()) {
                return $just;
            }
        }

        return $educators;
    }

    /**
     * The person emoji used everywhere else for somebody with no photo.
     *
     * Mirrors kt-emoji-avatars.js exactly, including its name hash, so a child without a
     * photo gets the SAME face on the printed card as on the screen. A different one
     * would read as a different child.
     */
    private function personEmoji(string $name, $sex = null, bool $isChild = false): string
    {
        $s = strtolower(trim((string) $sex));
        if (preg_match('/^(m|male|boy|man)$/', $s)) {
            $sexKey = 'male';
        } elseif (preg_match('/^(f|female|girl|woman)$/', $s)) {
            $sexKey = 'female';
        } else {
            // Same deterministic hash as the browser: h = (h * 31 + code) >>> 0.
            $h = 0;
            $len = mb_strlen($name);
            for ($i = 0; $i < $len; $i++) {
                $h = ($h * 31 + mb_ord(mb_substr($name, $i, 1))) & 0xFFFFFFFF;
            }
            $sexKey = ($h % 2 === 0) ? 'male' : 'female';
        }

        if ($isChild) {
            return $sexKey === 'female' ? '👧' : '👦';
        }

        return $sexKey === 'female' ? '👩' : '👨';
    }

    /** An empty safety field, said out loud rather than left as a dash. */
    private function missing(string $text): string
    {
        return '<span class="missing">'.$this->esc($text).'</span>';
    }

    /** A JSON array column, a comma list, or a plain string — all become a list. */
    private function listFrom($raw): array
    {
        if (is_array($raw)) { $arr = $raw; }
        elseif (! $raw || trim((string) $raw) === '' || trim((string) $raw) === '[]') { return []; }
        else {
            $decoded = json_decode((string) $raw, true);
            $arr = is_array($decoded) ? $decoded : explode(',', (string) $raw);
        }
        $out = [];
        foreach ($arr as $v) {
            if (is_array($v)) { $v = $v['allergen'] ?? $v['restriction'] ?? $v['alert'] ?? json_encode($v); }
            $v = trim((string) $v);
            if ($v !== '') { $out[] = $v; }
        }

        return $out;
    }

    private function esc(?string $s): string
    {
        return $s === null ? '' : htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
