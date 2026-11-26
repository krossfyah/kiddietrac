{{--
  THE REPORT CARD AS A DOCUMENT (redesigned 2026-11-26, Anthony: "the report card should have the agency information
  including educator information and nicely formatted with a signature from the agency as approval").

  It is the family's document, on the AGENCY's branding: its logo, name, colour, centre address and licence. No
  KiddieTrac mark on a white-label account. Dompdf draws it, so the layout is tables and plain CSS (no flex, grid or
  custom properties). Page numbers and the reference are drawn by ReportCardController::pdfResponse on every page.
  Labels follow the family's language (en / fr / es); the narratives are printed as written.
  All values come from ReportCardController::pdfData.
--}}
<!doctype html>
<html lang="{{ $lang }}"><head><meta charset="utf-8"><title>{{ $L['report_card'] }} · {{ $childName }}</title>
<style>
  @page { margin: 36px 42px 64px; }
  body { font-family: 'DejaVu Sans', sans-serif; color: #1E293B; font-size: 10.5pt; line-height: 1.55; }
  table { border-collapse: collapse; }

  .band { width: 100%; border-bottom: 3px solid {{ $color }}; }
  .band td { vertical-align: top; padding-bottom: 10px; }
  .logo { max-height: 54px; max-width: 190px; }
  .provider { font-size: 15pt; font-weight: bold; color: #0F172A; }
  .centre { font-size: 10pt; color: #334155; font-weight: bold; margin-top: 2px; }
  .contact { font-size: 8.5pt; color: #64748B; line-height: 1.45; margin-top: 3px; }
  .doctype { text-align: right; font-size: 8.5pt; letter-spacing: 1.6px; font-weight: bold; color: {{ $color }}; text-transform: uppercase; }
  .docmeta { text-align: right; font-size: 8.5pt; color: #64748B; line-height: 1.5; }

  h1 { font-size: 20pt; color: #0F172A; margin: 18px 0 2px; }
  .term { font-size: 11pt; color: {{ $color }}; font-weight: bold; margin: 0 0 14px; }

  .facts { width: 100%; margin: 0 0 6px; }
  .facts td { width: 50%; vertical-align: top; padding: 0; }
  .box { border: 1px solid #E2E8F0; border-radius: 8px; padding: 10px 12px; background: #F8FAFC; }
  .box-h { font-size: 7.5pt; letter-spacing: 1.2px; text-transform: uppercase; color: #64748B; font-weight: bold; margin: 0 0 5px; }
  .kv td { padding: 1.5px 0; font-size: 9.5pt; vertical-align: top; width: auto; }
  .kv td.k { color: #64748B; width: 96px; }
  .kv td.v { color: #0F172A; }

  .fw { font-size: 8.5pt; color: #64748B; margin: 10px 0 0; }
  .sec { page-break-inside: avoid; margin-top: 16px; }
  .sec h2 { font-size: 12pt; color: {{ $color }}; margin: 0 0 6px; padding-bottom: 4px; border-bottom: 1.5px solid {{ $color }}; }
  .sec p { margin: 0; white-space: pre-wrap; }
  .empty { color: #94A3B8; font-style: italic; }
  .next { page-break-inside: avoid; margin-top: 18px; background: #FFF7ED; border-left: 3px solid #F59E0B; padding: 10px 14px; border-radius: 0 6px 6px 0; }
  .next h2 { font-size: 12pt; color: #B45309; margin: 0 0 6px; }

  .signs { width: 100%; margin-top: 30px; page-break-inside: avoid; }
  .signs td { width: 50%; vertical-align: bottom; }
  .sig-img { height: 54px; display: block; margin-bottom: 2px; }
  .sig-blank { height: 54px; }
  .sig-line { border-top: 1px solid #334155; padding-top: 4px; font-size: 9pt; color: #334155; line-height: 1.45; }
  .sig-line .role { font-size: 7.5pt; letter-spacing: 1.1px; text-transform: uppercase; color: #64748B; font-weight: bold; }
  .seal { margin-top: 14px; page-break-inside: avoid; border: 1.5px solid #15803D; color: #15803D; border-radius: 8px; padding: 7px 12px;
          font-size: 9.5pt; font-weight: bold; text-align: center; }
  .seal-pending { border-color: #B45309; color: #B45309; }

  .wm { position: fixed; top: 330px; left: -60px; width: 820px; text-align: center; white-space: nowrap; font-size: 40pt; font-weight: bold;
        color: #DC2626; opacity: .10; transform: rotate(-32deg); }
  .foot { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 7.5pt; color: #94A3B8; border-top: 1px solid #E2E8F0; padding-top: 5px; }
</style></head>
<body>
  @if ($watermark)
    <div class="wm">{{ $watermark }}</div>
  @endif
  <div class="foot">
    {{ $providerName }}@if($ref) · {{ $L['reference'] }} {{ $ref }}@endif @if(! $whiteLabel) · {{ $poweredBy }}@endif
  </div>

  <table class="band"><tr>
    <td style="width:62%;">
      @if ($logo)
        <img src="{{ $logo }}" class="logo" alt="">
      @endif
      <div class="provider" @if($logo) style="margin-top:6px;" @endif>{{ $providerName }}</div>
      @if ($centreName && $centreName !== $providerName)
        <div class="centre">{{ $centreName }}</div>
      @endif
      <div class="contact">
        @if ($address){{ $address }}<br>@endif
        @if ($phone){{ $phone }}@endif @if ($phone && $email) · @endif @if ($email){{ $email }}@endif
        @if ($licence)<br>{{ $L['licence'] }} {{ $licence }}@endif
      </div>
    </td>
    <td style="width:38%;">
      <div class="doctype">{{ $L['report_card'] }}</div>
      <div class="docmeta">
        @if ($ref){{ $L['reference'] }} {{ $ref }}<br>@endif
        @if ($version > 1){{ $L['version'] }} {{ $version }}<br>@endif
        {{ $L['issued'] }} {{ $issued }}
      </div>
    </td>
  </tr></table>

  <h1>{{ $childName }}</h1>
  <div class="term">{{ $term }}</div>

  <table class="facts"><tr>
    <td style="padding-right:8px;">
      <div class="box">
        <div class="box-h">{{ $L['child'] }}</div>
        <table class="kv">
          <tr><td class="k">{{ $L['name'] }}</td><td class="v">{{ $childName }}</td></tr>
          @if ($childRef)<tr><td class="k">{{ $L['child_id'] }}</td><td class="v">{{ $childRef }}</td></tr>@endif
          @if ($room)<tr><td class="k">{{ $L['room'] }}</td><td class="v">{{ $room }}</td></tr>@endif
          <tr><td class="k">{{ $L['term'] }}</td><td class="v">{{ $term }}</td></tr>
        </table>
      </div>
    </td>
    <td style="padding-left:8px;">
      <div class="box">
        <div class="box-h">{{ $L['prepared_by'] }}</div>
        <table class="kv">
          <tr><td class="k">{{ $L['educator'] }}</td><td class="v">{{ $educatorName ?: '—' }}</td></tr>
          @if ($registry)<tr><td class="k">{{ $L['registration'] }}</td><td class="v">{{ $registry }}</td></tr>@endif
          @if ($room)<tr><td class="k">{{ $L['room'] }}</td><td class="v">{{ $room }}</td></tr>@endif
          @if ($centreName)<tr><td class="k">{{ $L['centre'] }}</td><td class="v">{{ $centreName }}</td></tr>@endif
        </table>
      </div>
    </td>
  </tr></table>

  @if ($frameworkName)
    <div class="fw">{{ $L['framework'] }}: {{ $frameworkName }}</div>
  @endif

  @foreach ($sections as $s)
    <div class="sec">
      <h2>{{ $s['label'] }}</h2>
      @if (trim((string) ($s['text'] ?? '')) !== '')
        <p>{{ trim((string) $s['text']) }}</p>
      @else
        <p class="empty">{{ $L['no_text'] }}</p>
      @endif
    </div>
  @endforeach

  @if (trim((string) ($nextSteps ?? '')) !== '')
    <div class="next">
      <h2>{{ $L['next_steps'] }}</h2>
      <div>{!! nl2br(e(trim((string) $nextSteps))) !!}</div>
    </div>
  @endif

  <table class="signs"><tr>
    <td style="padding-right:22px;">
      @if ($educatorSig)<img src="{{ $educatorSig }}" class="sig-img" alt="">@else<div class="sig-blank"></div>@endif
      <div class="sig-line">
        <span class="role">{{ $L['prepared_by'] }}</span><br>
        {{ $educatorName ?: '—' }}@if($registry) · {{ $L['registration'] }} {{ $registry }}@endif
        @if ($submitted)<br><span style="color:#64748B;">{{ $L['signed'] }} {{ $submitted }}</span>@endif
      </div>
    </td>
    <td style="padding-left:22px;">
      @if ($approverSig)<img src="{{ $approverSig }}" class="sig-img" alt="">@else<div class="sig-blank"></div>@endif
      <div class="sig-line">
        <span class="role">{{ $L['approved_by'] }}</span><br>
        {{ $approverName ?: '—' }}@if($approverTitle) · {{ $approverTitle }}@endif
        @if ($approved)<br><span style="color:#64748B;">{{ $L['approved'] }} {{ $approved }}</span>@endif
      </div>
    </td>
  </tr></table>

  @if ($approved && ! $watermark)
    <div class="seal">✔ {{ $L['seal'] }} · {{ $providerName }} · {{ $approved }}</div>
  @elseif (! $approved)
    <div class="seal seal-pending">{{ $L['not_approved'] }}</div>
  @endif
</body></html>
