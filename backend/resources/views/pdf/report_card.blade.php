{{--
  THE REPORT CARD AS A DOCUMENT - progress-report layout (2026-11-26, Anthony: lay it out like the Ontario
  elementary report card, on the agency's own logo and details).

  The LAYOUT of a provincial report card, not its identity: no government mark, no ministry wording, no
  "provincial" title. The agency's logo and name head the page; KiddieTrac appears only in the footer, and never on a
  white-label account. Ruled info grid, a two-column grid of grey panels with charcoal tabs (one per learning area of
  the card's framework), then full-width ruled boxes. The agency colour is a thin accent only (title rule, outer rule).

  Cards carry narratives, not ratings, so the panels hold the educator's narrative as bullets and the box on the
  right of the section heading names the framework - no rating letters are invented.

  Dompdf draws it: tables and plain CSS only. Helvetica for the print look; DejaVu Sans is the per-glyph fallback so
  any name or narrative character Helvetica lacks still prints. Page x of y is drawn by ReportCardController::pdfResponse.
  All values come from ReportCardController::pdfData.
--}}
@php
  $bullets = function ($text) {
      $text = trim((string) $text);
      if ($text === '') return [];
      $paras = preg_split('/\R+/u', $text) ?: [];
      $out = [];
      foreach ($paras as $p) {
          $p = trim(preg_replace('/^[\-\*•·]\s*/u', '', trim($p)));
          if ($p === '') continue;
          // A long paragraph reads as the reference's bullets: one sentence each.
          $parts = mb_strlen($p) > 220 ? preg_split('/(?<=[.!?])\s+(?=[A-ZÀ-ÖØ-Þ¿¡"“])/u', $p) : [$p];
          $buf = '';
          foreach ($parts as $x) {   // a fragment under ~70 characters stays with its neighbour, never a bullet of its own
              $x = trim($x); if ($x === '') continue;
              $buf = $buf === '' ? $x : $buf . ' ' . $x;
              if (mb_strlen($buf) >= 70) { $out[] = $buf; $buf = ''; }
          }
          if ($buf !== '') { if ($out && mb_strlen($buf) < 70) $out[count($out) - 1] .= ' ' . $buf; else $out[] = $buf; }
      }
      return $out;
  };
  $pairs = array_chunk($sections, 2);
  $att = $attendance ?? null;
  $dash = '';
@endphp
<!doctype html>
<html lang="{{ $lang }}"><head><meta charset="utf-8"><title>{{ $L['progress_report'] }} · {{ $childName }}</title>
<style>
  @page { margin: 30px 34px 58px; }
  body { font-family: Helvetica, 'DejaVu Sans', sans-serif; color: #000; font-size: 9pt; line-height: 1.3; }
  table { border-collapse: collapse; }
  .sym { font-family: 'DejaVu Sans', sans-serif; }

  .head { width: 100%; }
  .head td { vertical-align: middle; padding: 0 0 6px; }
  .logo { max-height: 46px; max-width: 150px; }
  .agency { font-size: 11pt; font-weight: bold; }
  .agency-sub { font-size: 7.5pt; color: #333; margin-top: 1px; }
  .title { font-size: 17pt; font-weight: bold; text-align: right; line-height: 1.1; }
  .title-term { font-size: 11pt; font-weight: bold; text-align: right; margin-top: 2px; }
  .title-date { font-size: 8.5pt; font-weight: bold; text-align: right; margin-top: 4px; }
  .rule { border-top: 2.5px solid {{ $color }}; height: 0; margin: 0 0 6px; }

  .grid { width: 100%; table-layout: fixed; }
  .grid td { border: 1px solid #000; vertical-align: top; padding: 2px 5px 4px; height: 26px; }
  .lbl { font-size: 7pt; display: block; }
  .val { font-size: 9.5pt; font-weight: bold; display: block; margin-top: 1px; }
  .val-sm { font-size: 8pt; display: block; margin-top: 1px; line-height: 1.3; }
  .sub { font-size: 7pt; color: #333; display: block; }

  .start { margin: 12px 0 10px; font-size: 9pt; font-weight: bold; text-transform: uppercase; }
  .start .blank { display: inline-block; min-width: 150px; border-bottom: 1px solid #000; padding: 0 6px; text-transform: none; font-weight: bold; }

  .sechead { width: 100%; margin-top: 4px; }
  .sechead td { vertical-align: bottom; padding: 0; }
  .sectitle { font-size: 11pt; font-weight: bold; padding-bottom: 3px !important; }
  .legend { border: 1.5px solid #000; border-bottom: 0; padding: 3px 8px !important; font-size: 8pt; text-align: center; }

  .panels { width: 100%; table-layout: fixed; border: 1px solid #000; }
  .panels td.p { width: 50%; vertical-align: top; background: #D9D9D9; border: 1px solid #000; padding: 0; }
  .tab { background: #3A3A3A; color: #fff; font-weight: bold; font-size: 9.5pt; padding: 3px 8px; display: inline-block; min-width: 46%; }
  .focus { font-size: 7.5pt; font-style: italic; color: #222; margin: 4px 8px 0; }
  ul.b { margin: 4px 8px 7px 0; padding: 0 0 0 20px; }
  ul.b li { margin: 0 0 2px; font-size: 8.5pt; line-height: 1.32; }
  .empty { font-size: 8.5pt; font-style: italic; color: #444; margin: 5px 8px 8px; }
  .panelrow { page-break-inside: avoid; }

  .box { border: 1px solid #000; margin-top: 10px; page-break-inside: avoid; }
  .box-h { font-size: 10pt; font-weight: bold; padding: 3px 6px; border-bottom: 1px solid #000; }
  .box-b { padding: 6px 8px 8px; font-size: 9pt; line-height: 1.42; min-height: 40px; }

  .signs { width: 100%; table-layout: fixed; border: 1px solid #000; margin-top: 10px; page-break-inside: avoid; }
  .signs td { vertical-align: top; padding: 4px 10px 6px; border: 1px solid #000; }
  .sig-img { height: 44px; display: block; }
  .sig-blank { height: 44px; }
  .sig-line { border-top: 1px solid #000; padding-top: 3px; font-size: 8.5pt; min-height: 46px; }
  .sig-stamp { font-size: 7pt; color: #333; line-height: 1.35; }   /* signature stamp (2026-11-27) */
  .sig-role { font-size: 7pt; text-transform: uppercase; font-weight: bold; }
  .approved { margin-top: 8px; font-size: 9pt; font-weight: bold; page-break-inside: avoid; }
  .approved.pending { color: #8A4B00; }

  .wm { position: fixed; top: 330px; left: -60px; width: 820px; text-align: center; white-space: nowrap; font-size: 40pt; font-weight: bold;
        color: #DC2626; opacity: .10; transform: rotate(-32deg); }
  .foot { position: fixed; bottom: -38px; left: 0; right: 0; font-size: 7pt; color: #333; border-top: 1px solid {{ $color }}; padding-top: 4px; }
</style></head>
<body>
  @if ($watermark)
    <div class="wm">{{ $watermark }}</div>
  @endif
  <div class="foot">
    {{ $providerName }}@if($ref) · {{ $L['reference'] }} {{ $ref }}@endif @if($version > 1) · {{ $L['version'] }} {{ $version }}@endif @if(! $whiteLabel) · {{ $poweredBy }}@endif
  </div>

  {{-- header: agency logo + name on the left, the document title on the right --}}
  <table class="head"><tr>
    <td style="width:56%;">
      <table><tr>
        @if ($logo)<td style="padding:0 10px 0 0; vertical-align:middle;"><img src="{{ $logo }}" class="logo" alt=""></td>@endif
        <td style="padding:0; vertical-align:middle;">
          <div class="agency">{{ $providerName }}</div>
          @if ($centreName && $centreName !== $providerName)<div class="agency-sub">{{ $centreName }}</div>@endif
        </td>
      </tr></table>
    </td>
    <td style="width:44%;">
      <div class="title">{{ $L['progress_report'] }}</div>
      <div class="title-term">{{ $term }}</div>
      <div class="title-date">{{ $L['date'] }}: {{ $issued }}</div>
    </td>
  </tr></table>
  <div class="rule"></div>

  {{-- info grid --}}
  <table class="grid">
    <tr>
      <td style="width:40%;"><span class="lbl">{{ $L['child'] }}:</span><span class="val">{{ $childName }}</span>@if($childRef)<span class="sub">{{ $L['child_id'] }} {{ $childRef }}</span>@endif</td>
      <td style="width:22%;"><span class="lbl">{{ $L['reference'] }}:</span><span class="val">{{ $ref }}</span>@if($version > 1)<span class="sub">{{ $L['version'] }} {{ $version }}</span>@endif</td>
      <td style="width:19%;"><span class="lbl">{{ $L['days_absent'] }}:</span><span class="val">{{ $att ? $att['absent'] : $dash }}</span></td>
      <td style="width:19%;"><span class="lbl">{{ $L['days_late'] }}:</span><span class="val">{{ $att ? $att['late'] : $dash }}</span></td>
    </tr>
  </table>
  <table class="grid">
    <tr>
      <td style="width:28%; border-top:0;"><span class="lbl">{{ $L['room_age'] }}:</span><span class="val">{{ $roomAge ?: $dash }}</span></td>
      <td style="width:53%; border-top:0;"><span class="lbl">{{ $L['educator'] }}:</span><span class="val">{{ $educatorName ?: $dash }}</span>@if($registry)<span class="sub">{{ $L['registration'] }} {{ $registry }}</span>@endif</td>
      <td style="width:19%; border-top:0;"><span class="lbl">{{ $L['late_pickups'] }}:</span><span class="val">{{ $att ? $att['pickups'] : $dash }}</span></td>
    </tr>
  </table>
  <table class="grid">
    <tr>
      <td style="width:45%; border-top:0;" rowspan="2">
        <span class="lbl">{{ $L['agency'] }}:</span><span class="val">{{ $providerName }}</span>
        <span class="lbl" style="margin-top:5px;">{{ $L['address'] }}:</span><span class="val-sm">{{ $agencyAddress ?: $dash }}</span>
        @if ($agencyPhone)<span class="lbl" style="margin-top:5px;">{{ $L['telephone'] }}:</span><span class="val-sm">{{ $agencyPhone }}</span>@endif
      </td>
      <td colspan="2" style="width:55%; border-top:0; height:52px;">
        <span class="lbl">{{ $L['centre'] }}:</span><span class="val">{{ $centreName ?: $providerName }}</span>
        <span class="lbl" style="margin-top:5px;">{{ $L['address'] }}:</span>
        <span class="val-sm">{{ $address ?: $dash }}@if($email)<br>{{ $email }}@endif @if($licence)<br>{{ $L['licence'] }} {{ $licence }}@endif</span>
      </td>
    </tr>
    <tr>
      @php
        $dirAdm = array_merge(array_map(fn ($n) => [$n, $L['director']], $directors ?? []), array_map(fn ($n) => [$n, $L['administrator']], $admins ?? []));
        $dirCols = count($dirAdm) > 3 ? array_chunk($dirAdm, (int) ceil(count($dirAdm) / 2)) : [$dirAdm];
      @endphp
      <td style="width:30%;"><span class="lbl">{{ $L['director_lbl'] }}:</span>@if($dirAdm)<table style="width:100%; border-collapse:collapse; margin-top:1px;"><tr>@foreach($dirCols as $col)<td style="border:0; padding:0 4px 0 0; vertical-align:top; width:{{ count($dirCols) > 1 ? '50%' : '100%' }};">@foreach($col as $p)<div style="font-size:7.6pt; line-height:1.3; font-weight:bold;">{{ $p[0] }} <span style="font-weight:normal; color:#64748B; font-size:6.6pt;">{{ $p[1] }}</span></div>@endforeach</td>@endforeach</tr></table>@else<span class="val">{{ $dash }}</span>@endif</td>
      <td style="width:25%;"><span class="lbl">{{ $L['telephone'] }}:</span><span class="val">{{ $phone ?: $dash }}</span></td>
    </tr>
  </table>

  <div class="start">{{ $L['room_start'] }} <span class="sym">➜</span> <span class="blank">{{ $room ?: '' }}</span></div>

  {{-- learning areas: heading row with the framework in the bordered box, then the panel grid --}}
  <table class="sechead"><tr>
    <td class="sectitle" style="width:50%;">{{ $L['learning'] }}</td>
    <td style="width:50%; text-align:right;">@if($frameworkName)<table style="width:100%;"><tr><td class="legend">{{ $L['framework'] }}: {{ $frameworkName }}</td></tr></table>@endif</td>
  </tr></table>
  <table class="panels">
    @foreach ($pairs as $pair)
      <tr class="panelrow">
        @foreach ($pair as $s)
          <td class="p">
            <div class="tab">{{ $s['label'] }}</div>
            @if (! empty($s['hint']))<div class="focus">{{ $L['focus'] }}: {{ $s['hint'] }}</div>@endif
            @php $bs = $bullets($s['text'] ?? ''); @endphp
            @if ($bs)
              <ul class="b">@foreach ($bs as $b)<li>{{ $b }}</li>@endforeach</ul>
            @else
              <div class="empty">{{ $L['no_text'] }}</div>
            @endif
          </td>
        @endforeach
        @if (count($pair) === 1)<td class="p" style="background:#fff;"></td>@endif
      </tr>
    @endforeach
  </table>

  {{-- strengths / next steps --}}
  <div class="box">
    <div class="box-h">{{ $L['strengths'] }}</div>
    <div class="box-b">@if (trim((string) ($nextSteps ?? '')) !== ''){!! nl2br(e(trim((string) $nextSteps))) !!}@else<span style="font-style:italic;color:#444;">{{ $L['no_text'] }}</span>@endif</div>
  </div>

  {{-- signatures --}}
  <table class="signs"><tr>
    <td style="width:50%;">
      @if ($educatorSig)<img src="{{ $educatorSig }}" class="sig-img" alt="">@else<div class="sig-blank"></div>@endif
      <div class="sig-line">
        <span class="sig-role">{{ $L['prepared_by'] }}</span><br>
        {{ $educatorName ?: '—' }}@if($registry) · {{ $L['registration'] }} {{ $registry }}@endif
        @if (! empty($educatorStamp['lines']))<div class="sig-stamp">{!! implode('<br>', array_map('e', $educatorStamp['lines'])) !!}</div>@elseif ($submitted)<br>{{ $L['signed'] }} {{ $submitted }}@endif
      </div>
    </td>
    <td style="width:50%;">
      @if ($approverSig)<img src="{{ $approverSig }}" class="sig-img" alt="">@else<div class="sig-blank"></div>@endif
      <div class="sig-line">
        <span class="sig-role">{{ $L['approved_by'] }}</span><br>
        {{ $approverName ?: '—' }}@if($approverTitle) · {{ $approverTitle }}@endif
        @if (! empty($approverStamp['lines']))<div class="sig-stamp">{!! implode('<br>', array_map('e', $approverStamp['lines'])) !!}</div>@elseif ($approved)<br>{{ $L['approved'] }} {{ $approved }}@endif
      </div>
    </td>
  </tr></table>

  @if ($approved && ! $watermark)
    <div class="approved"><span class="sym">✔</span> {{ $L['seal'] }} · {{ $providerName }} · {{ $approved }}@if($ref) · {{ $L['reference'] }} {{ $ref }}@endif</div>
  @elseif (! $approved)
    <div class="approved pending">{{ $L['not_approved'] }}@if($ref) · {{ $L['reference'] }} {{ $ref }}@endif</div>
  @endif
</body></html>
