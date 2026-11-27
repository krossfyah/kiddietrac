{{-- KiddieTrac Payroll Engine P1 (2026-11-27): the employee's TD1 (federal + provincial) or Quebec TP-1015.3 claim, as a
     Managed Forms PDF they e-sign (the signature stamp is added by Managed Forms when they sign). A summary of the claim
     amounts the employee entered, not a copy of the CRA form. The SIN is never printed: only "on file" and its last digits. --}}
<!doctype html>
<html><head><meta charset="utf-8"><style>
  @page { margin: 50px 60px; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #111827; line-height: 1.5; }
  .head { border-bottom: 2px solid {{ $colour }}; padding-bottom: 10px; margin-bottom: 18px; }
  .head img { max-height: 46px; max-width: 200px; }
  .agency { font-size: 15px; font-weight: bold; color: {{ $colour }}; }
  h1 { font-size: 15px; margin: 0 0 4px; }
  h2 { font-size: 12.5px; margin: 18px 0 6px; color: {{ $colour }}; }
  table.k { width: 100%; border-collapse: collapse; }
  table.k td { padding: 5px 6px; border-bottom: 1px solid #E5E7EB; vertical-align: top; }
  table.k td.v { text-align: right; font-weight: bold; white-space: nowrap; width: 32%; }
  .small { font-size: 9.5px; color: #6B7280; }
  .cert { margin-top: 18px; padding: 10px 12px; border: 1px solid #CBD5E1; background: #F8FAFC; }
  .line { border-top: 1px solid #111827; margin-top: 44px; padding-top: 4px; font-size: 10.5px; width: 60%; }
</style></head><body>
@php
  $m = fn ($v) => $v === null ? '—' : '$' . number_format((float) $v, 2);
  $f = $claims['federal'] ?? [];
  $p = $claims['provincial'] ?? [];
  $q = $claims['quebec'] ?? [];
@endphp
  <div class="head">
    @if ($logo)<img src="{{ $logo }}" alt=""><br>@endif
    <span class="agency">{{ $agency }}</span>
  </div>
  <h1>{{ $form === 'TP-1015.3' ? 'TD1 and TP-1015.3: tax claim' : 'TD1: personal tax credits return' }} {{ $year }}</h1>
  <div>{{ $person }} &middot; works in {{ $province }} &middot; {{ $today }}</div>
  <div class="small">Social insurance number: {{ $sin ? 'on file with your employer (ending ' . $sin . ')' : 'not on file: give it to your employer separately' }}.</div>

  <h2>Federal (CRA Form TD1, {{ $year }})</h2>
  <table class="k">
    @if (! empty($f['more_than_one_employer']))
      <tr><td>I have more than one employer or payer at the same time and have already claimed my credits with another: total claim amount is $0</td><td class="v">$0.00</td></tr>
    @else
      <tr><td>Total claim amount (line 13) @if (! isset($f['total_claim']))<div class="small">Left blank: the basic personal amount ({{ $m($basics['federal_basic'] ?? null) }}) is used.</div>@endif</td><td class="v">{{ $m($f['total_claim'] ?? ($basics['federal_basic'] ?? null)) }}</td></tr>
    @endif
    @if (! empty($f['exempt']))<tr><td>My total income this year will be less than my total claim amount: no federal tax is to be deducted</td><td class="v">Yes</td></tr>@endif
    @if (isset($f['zone_deduction']))<tr><td>Deduction for living in a prescribed zone (annual)</td><td class="v">{{ $m($f['zone_deduction']) }}</td></tr>@endif
    @if (isset($f['additional_tax']))<tr><td>Additional tax to deduct from each pay</td><td class="v">{{ $m($f['additional_tax']) }}</td></tr>@endif
  </table>

  @if ($form === 'TP-1015.3')
    <h2>Quebec (Revenu Québec Form TP-1015.3, {{ $year }})</h2>
    <table class="k">
      <tr><td>Basic amount and other credits (line 7) @if (! isset($q['line7']))<div class="small">Left blank: the basic amount ({{ $m($basics['quebec_basic'] ?? null) }}) is used.</div>@endif</td><td class="v">{{ $m($q['line7'] ?? ($basics['quebec_basic'] ?? null)) }}</td></tr>
      @if (isset($q['line9']))<tr><td>Amount transferred from a spouse or other credits (line 9)</td><td class="v">{{ $m($q['line9']) }}</td></tr>@endif
      @if (isset($q['line19']))<tr><td>Deductions reducing income subject to source deductions (line 19)</td><td class="v">{{ $m($q['line19']) }}</td></tr>@endif
      @if (! empty($q['exempt']))<tr><td>I am exempt from Quebec income tax source deductions</td><td class="v">Yes</td></tr>@endif
      @if (isset($q['additional_tax']))<tr><td>Additional Quebec tax to deduct from each pay</td><td class="v">{{ $m($q['additional_tax']) }}</td></tr>@endif
    </table>
  @else
    <h2>{{ $province }} (CRA Form TD1{{ $jurisdiction }}, {{ $year }})</h2>
    <table class="k">
      @if (! empty($f['more_than_one_employer']))
        <tr><td>Total claim amount: $0 (claimed with another employer)</td><td class="v">$0.00</td></tr>
      @else
        <tr><td>Total claim amount @if (! isset($p['total_claim']))<div class="small">Left blank: the basic personal amount ({{ $m($basics['provincial_basic'] ?? null) }}) is used.</div>@endif</td><td class="v">{{ $m($p['total_claim'] ?? ($basics['provincial_basic'] ?? null)) }}</td></tr>
      @endif
      @if (! empty($p['exempt']))<tr><td>My total income this year will be less than my provincial claim amount</td><td class="v">Yes</td></tr>@endif
    </table>
  @endif

  <div class="cert">I certify that the information given here is correct and complete. I worked out my claim amounts with the
    {{ $year }} CRA Form TD1{{ $form === 'TP-1015.3' ? ' and Revenu Québec Form TP-1015.3' : ' and TD1' . $jurisdiction }} worksheets.
    I will give my employer a new form within 7 days if my situation changes.
    <div class="small" style="margin-top:4px;">Keep the worksheets: your employer does not need them. The CRA forms are at canada.ca (search "TD1 {{ $year }}").</div></div>
  <div class="line"><b>{{ $person }}</b><br><span class="small">Employee &middot; signature and date</span></div>
</body></html>
