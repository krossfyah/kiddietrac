<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
{{-- KiddieTrac HR course completion certificate (2026-11-28). Issued in the agency's name only: no person signs it. --}}
<style>
  @page { margin: 30px 36px; }
  body { font-family: 'DejaVu Sans', sans-serif; color: #0F172A; }
  .frame { border: 3px solid {{ $colour }}; border-radius: 14px; padding: 34px 46px 26px; height: 470px; position: relative; }
  .inner { border: 1px solid #E2E8F0; border-radius: 10px; padding: 26px 30px 20px; height: 420px; text-align: center; }
  .logo { max-height: 64px; max-width: 220px; margin-bottom: 6px; }
  .org { font-size: 15px; font-weight: bold; color: #334155; margin-bottom: 18px; }
  .kind { font-size: 12.5px; font-weight: bold; letter-spacing: 3px; text-transform: uppercase; color: {{ $colour }}; }
  h1 { font-size: 30px; margin: 8px 0 12px; }
  .to { font-size: 12.5px; color: #475569; }
  .name { font-size: 27px; font-weight: bold; margin: 6px 0 14px; border-bottom: 1px solid #CBD5E1; display: inline-block; padding: 0 30px 6px; }
  .course { font-size: 17px; font-weight: bold; margin: 6px 0 4px; }
  .meta { font-size: 11.5px; color: #475569; margin-top: 12px; }
  .meta td { padding: 0 18px; text-align: center; }
  .meta b { display: block; font-size: 12.5px; color: #0F172A; }
  .foot { font-size: 9.5px; color: #64748B; margin-top: 10px; }
  .stamp { font-size: 8.5px; color: #64748B; margin-top: 2px; }
</style>
</head>
<body>
<div class="frame"><div class="inner">
  @if ($logo)<img class="logo" src="{{ $logo }}"><br>@endif
  <div class="org">{{ $agency->name }}</div>
  <div class="kind">Certificate of completion</div>
  <h1>Course completed</h1>
  <div class="to">This certifies that</div>
  <div class="name">{{ $person }}</div>
  <div class="to">has completed</div>
  <div class="course">{{ $course }}</div>
  <table class="meta" align="center"><tr>
    <td><b>{{ $date }}</b>Completed on</td>
    @if ($hours)<td><b>{{ rtrim(rtrim(number_format($hours, 2), '0'), '.') }} {{ $hours == 1 ? 'hour' : 'hours' }}</b>Professional development</td>@endif
    <td><b>{{ $number }}</b>Certificate number</td>
  </tr></table>
  @if ($ack)<div class="foot">Acknowledged by {{ $ack }}: "I have completed this course and understood it."</div>@if (!empty($ackStamp['lines']))<div class="stamp">{!! implode(' &middot; ', array_map('e', $ackStamp['lines'])) !!}</div>@endif @endif
  <div class="foot">Issued by {{ $agency->name }}.</div>
</div></div>
</body>
</html>
