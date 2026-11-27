{{-- KiddieTrac HR Phase 3, item 12 (2026-11-26): an HR letter sent for e-signature (App\Support\HrLetters). Never shows the
     "Draft – have an employment lawyer review" note: that is for the editor only. Formal document: full names. --}}
<!doctype html>
<html><head><meta charset="utf-8"><style>
  @page { margin: 54px 64px; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11.5px; color: #111827; line-height: 1.55; }
  .head { border-bottom: 2px solid {{ $colour }}; padding-bottom: 10px; margin-bottom: 22px; }
  .head img { max-height: 46px; max-width: 200px; }
  .agency { font-size: 15px; font-weight: bold; color: {{ $colour }}; }
  .addr { font-size: 10px; color: #6B7280; }
  h1 { font-size: 16px; margin: 0 0 14px; }
  .date { margin-bottom: 16px; }
  p { margin: 0 0 11px; }
  .sigs { margin-top: 34px; width: 100%; border-collapse: collapse; }
  .sigs td { width: 50%; vertical-align: top; padding: 0 16px 0 0; }
  .line { border-top: 1px solid #111827; margin-top: 46px; padding-top: 4px; font-size: 10.5px; }
  .small { font-size: 10px; color: #6B7280; }
</style></head><body>
  <div class="head">
    @if ($logo)<img src="{{ $logo }}" alt=""><br>@endif
    <span class="agency">{{ $agency }}</span>
    @if ($address)<div class="addr">{{ $address }}</div>@endif
  </div>
  <h1>{{ $title }}</h1>
  <div class="date">{{ $today }}</div>
  @if ($kind !== 'contract' && $kind !== 'contractor')<div style="margin-bottom:14px;">{{ $person }}</div>@endif
  {!! $body !!}
  <table class="sigs"><tr>
    <td><div class="line"><b>{{ $signatory }}</b>@if ($signatoryTitle), {{ $signatoryTitle }}@endif<br><span class="small">For {{ $agency }}</span></div></td>
    <td><div class="line"><b>{{ $person }}</b><br><span class="small">{{ $kind === 'contractor' ? 'Contractor' : 'Employee' }} &middot; Date signed</span></div></td>
  </tr></table>
</body></html>
