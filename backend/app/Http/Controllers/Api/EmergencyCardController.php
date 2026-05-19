<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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
                'guardians.relationship', 'guardians.is_primary', 'guardians.can_pickup',
            ]);

        $medications = DB::table('medications')
            ->where('child_id', $child->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->get(['name', 'strength', 'dosage', 'frequency', 'special_instructions']);

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
            'centre' => $centre,
            'room' => $room,
            'age' => $age,
            'health_flags' => $healthFlags,
            'guardians' => $guardians,
            'medications' => $medications,
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

  @page { size: letter; margin: 0.5in; }
  @media print {
    body { background: white; padding: 0; }
    .toolbar { display: none; }
    .card { box-shadow: none; border: 1px solid var(--kt-border); margin: 0; max-width: none; page-break-after: always; page-break-inside: avoid; }
    .card:last-child { page-break-after: auto; }
  }
</style>
</head>
<body>
  <div class="toolbar">
    <div>
      <h1>{$title}</h1>
      <p>Generated {$generatedAt} · Print or Save as PDF (⌘/Ctrl+P)</p>
    </div>
    <button class="btn" onclick="window.print()">🖨 Print / Save PDF</button>
  </div>
  {$cardsHtml}
</body>
</html>
HTML;
    }

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
        $address = $this->esc(trim(($family->address_line1 ?? '').' '.($family->city ?? '').' '.($family->province ?? '').' '.($family->postal_code ?? '')));
        $hcLast4 = $this->esc($child->health_card_last4 ?: '');
        $hcDisplay = $hcLast4 ? "xxxx-xxxx-{$hcLast4}" : '—';
        $doctor = $this->esc($child->doctor_name ?: '—');
        $doctorPhone = $this->esc($child->doctor_phone ?: '');
        $medical = $this->esc($child->medical_notes ?: '—');
        $dietary = $this->esc($child->dietary_notes ?: '—');

        $photo = '';
        if ($child->photo_url) {
            $photo = '<img src="'.$this->esc($child->photo_url).'" alt="">';
        } else {
            $photo = $this->esc(mb_substr($child->preferred_name ?: $child->first_name, 0, 1));
        }

        $agencyLogo = '';
        if ($centre && $centre->logo_url) {
            $agencyLogo = '<img src="'.$this->esc($centre->logo_url).'" alt="">';
        }
        $centreName = $centre ? $this->esc($centre->name) : '';

        // Health alerts (allergies + conditions): inline red boxes.
        $alertsHtml = '';
        $allergies = [];
        $conditions = [];
        foreach ($c['health_flags'] as $hf) {
            if (($hf->flag_type ?? '') === 'allergy') $allergies[] = $hf;
            else $conditions[] = $hf;
        }
        if (! empty($allergies) || ! empty($conditions)) {
            $alertsHtml = '<div class="section alert-list">'.'<h3>⚠️ Critical alerts</h3>';
            foreach ($allergies as $a) {
                $alertsHtml .= '<div class="alert"><strong>ALLERGY:</strong> '.$this->esc($a->detail ?? $a->name ?? 'unspecified').'</div>';
            }
            foreach ($conditions as $a) {
                $alertsHtml .= '<div class="alert"><strong>'.$this->esc(strtoupper($a->flag_type ?? 'CONDITION')).':</strong> '.$this->esc($a->detail ?? $a->name ?? 'unspecified').'</div>';
            }
            $alertsHtml .= '</div>';
        }

        // Guardians table
        $guardiansHtml = '<div class="section guardians"><h3>Authorized pickups & contacts</h3><table><thead><tr><th>Name</th><th>Rel.</th><th>Phone</th><th>Pickup?</th></tr></thead><tbody>';
        if (count($c['guardians']) === 0) {
            $guardiansHtml .= '<tr><td colspan="4" style="color:var(--kt-faint);padding:8px;">No guardians on record</td></tr>';
        } else {
            foreach ($c['guardians'] as $g) {
                $gName = $this->esc(trim(($g->first_name ?? '').' '.($g->last_name ?? ''))).($g->is_primary ? ' <span class="badge">PRIMARY</span>' : '');
                $rel = $this->esc($g->relationship ?: '—');
                $phone = $this->esc($g->phone ?: '—');
                $pickup = $g->can_pickup ? '✓' : '<span class="badge badge-no-pickup">NO</span>';
                $guardiansHtml .= "<tr><td>{$gName}</td><td>{$rel}</td><td>{$phone}</td><td>{$pickup}</td></tr>";
            }
        }
        $guardiansHtml .= '</tbody></table></div>';

        // Medications table
        $medsHtml = '';
        if (count($c['medications']) > 0) {
            $medsHtml = '<div class="section meds"><h3>Active medications</h3><table><thead><tr><th>Drug</th><th>Dose / Strength</th><th>Frequency</th><th>Special instructions</th></tr></thead><tbody>';
            foreach ($c['medications'] as $m) {
                $dose = trim(($m->dosage ?? '').' '.($m->strength ?? ''));
                $medsHtml .= '<tr>'
                    .'<td><strong>'.$this->esc($m->name ?? '').'</strong></td>'
                    .'<td>'.$this->esc($dose ?: '—').'</td>'
                    .'<td>'.$this->esc($m->frequency ?? '').'</td>'
                    .'<td>'.$this->esc($m->special_instructions ?? '').'</td>'
                    .'</tr>';
            }
            $medsHtml .= '</tbody></table></div>';
        }

        return <<<HTML
<div class="card">
  <div class="card-head">
    <div class="photo">{$photo}</div>
    <div>
      <h2>{$name}</h2>
      <div class="meta">{$age} · DOB {$dob} · {$gender}</div>
      <div class="meta">{$familyName} family · {$roomName}</div>
    </div>
    <div class="agency-logo">
      {$agencyLogo}
      <div>{$centreName}</div>
    </div>
  </div>

  {$alertsHtml}

  <div class="section">
    <h3>Identifiers</h3>
    <div class="info-row"><span class="lbl">Health card</span><span>{$hcDisplay}</span></div>
    <div class="info-row"><span class="lbl">Doctor</span><span>{$doctor} · {$doctorPhone}</span></div>
    <div class="info-row"><span class="lbl">Address</span><span>{$address}</span></div>
  </div>

  <div class="section">
    <h3>Medical & dietary</h3>
    <div class="info-row"><span class="lbl">Medical notes</span><span>{$medical}</span></div>
    <div class="info-row"><span class="lbl">Dietary notes</span><span>{$dietary}</span></div>
  </div>

  {$guardiansHtml}
  {$medsHtml}

  <div class="footer-stamp">
    <span>Card {$idx} of {$total}</span>
    <span>Confidential — do not redistribute</span>
  </div>
</div>
HTML;
    }

    private function esc(?string $s): string
    {
        return $s === null ? '' : htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
