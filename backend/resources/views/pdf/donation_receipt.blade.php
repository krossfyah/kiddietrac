<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  @page { margin: 36px 42px; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11.5px; color: #0F172A; }
  .top { width: 100%; border-bottom: 3px solid {{ $agency->brand_primary_color ?? '#1F6080' }}; padding-bottom: 12px; margin-bottom: 16px; }
  .top td { vertical-align: top; }
  .logo { max-height: 56px; max-width: 180px; }
  h1 { font-size: 17px; margin: 0 0 2px; }
  .kind { font-size: 12.5px; font-weight: bold; color: {{ $agency->brand_primary_color ?? '#1F6080' }}; text-transform: uppercase; letter-spacing: .5px; }
  .grid { width: 100%; border-collapse: collapse; margin-top: 8px; }
  .grid td { padding: 7px 8px; border: 1px solid #E2E8F0; vertical-align: top; }
  .grid td.l { width: 34%; background: #F8FAFC; color: #475569; font-weight: bold; }
  .amount { font-size: 15px; font-weight: bold; }
  .note { margin-top: 14px; padding: 10px 12px; background: #F1F5F9; border-radius: 6px; font-size: 10.5px; color: #334155; line-height: 1.5; }
  .void { position: fixed; top: 300px; left: 70px; font-size: 110px; color: rgba(220, 38, 38, 0.18); transform: rotate(-24deg); font-weight: bold; }
  .sig { margin-top: 26px; width: 100%; }
  .sig img { max-height: 54px; }
  .line { border-top: 1px solid #94A3B8; width: 260px; padding-top: 3px; font-size: 10.5px; color: #475569; }
  .foot { margin-top: 22px; font-size: 9.5px; color: #64748B; }
</style>
</head>
<body>
@if ($r->status === 'void')<div class="void">VOID</div>@endif
@php $logo = $agency->brand_logo_url ?? ($agency->logo_url ?? null); $official = $r->kind === 'official'; @endphp
<table class="top"><tr>
  <td>
    <h1>{{ $c['legal_name'] ?: ($c['name'] ?? '') }}</h1>
    @if (!empty($c['address']))<div>{{ $c['address'] }}</div>@endif
    @if ($official && !empty($c['number']))<div>Charitable registration number: <strong>{{ $c['number'] }}</strong></div>@endif
  </td>
  <td style="text-align:right">@if ($logo)<img class="logo" src="{{ $logo }}">@endif</td>
</tr></table>

<div class="kind">{{ $official ? 'Official receipt for income tax purposes' : 'Acknowledgement of your gift' }}</div>
@if ($replaces)<div style="margin-top:4px;font-weight:bold;">This receipt cancels and replaces receipt {{ $replaces }}.</div>@endif
@if ($r->status === 'void')<div style="margin-top:4px;color:#B91C1C;font-weight:bold;">This receipt has been voided{{ $r->void_reason ? ': ' . $r->void_reason : '' }}.</div>@endif

<table class="grid">
  <tr><td class="l">{{ $official ? 'Receipt number' : 'Reference' }}</td><td>{{ $r->serial }}</td></tr>
  <tr><td class="l">Donor</td><td>{{ $r->donor_name }}@if ($r->donor_address)<br>{{ $r->donor_address }}@endif</td></tr>
  <tr><td class="l">Date gift received</td><td>{{ \Illuminate\Support\Carbon::parse($r->received_on)->format('F j, Y') }}</td></tr>
  <tr><td class="l">Date receipt issued</td><td>{{ \Illuminate\Support\Carbon::parse($r->issued_on)->format('F j, Y') }}</td></tr>
  @if ($r->issued_at_location)<tr><td class="l">Location issued</td><td>{{ $r->issued_at_location }}</td></tr>@endif
  <tr><td class="l">Total amount of gift</td><td>{{ $money($r->amount) }}</td></tr>
  @if ((float) $r->advantage_amount > 0)
  <tr><td class="l">Value of advantage received</td><td>{{ $money($r->advantage_amount) }}</td></tr>
  @endif
  <tr><td class="l">{{ $official ? 'Eligible amount of gift for tax purposes' : 'Amount of gift' }}</td><td class="amount">{{ $money($official ? $r->eligible_amount : $r->amount) }}</td></tr>
</table>

@if ($official)
  <table class="sig"><tr><td>
    @if (!empty($c['signature']))<img src="{{ $c['signature'] }}"><br>@else<div style="height:40px"></div>@endif
    <div class="line">{{ $c['signatory_name'] ?? '' }}{{ !empty($c['signatory_title']) ? ', ' . $c['signatory_title'] : '' }} — Authorized signature</div>
  </td></tr></table>
  <div class="note">Canada Revenue Agency: <strong>www.canada.ca/charities-giving</strong></div>
@else
  <div class="note">Thank you for your generous support. <strong>This is not an official receipt for income tax purposes.</strong>
    @if (!empty($c['number'])) An official receipt can be issued once we have your full name and mailing address — please contact us. @endif
  </div>
@endif
<div class="foot">Thank you for supporting {{ $c['name'] ?? '' }}. Please keep this document with your records.</div>
</body>
</html>
