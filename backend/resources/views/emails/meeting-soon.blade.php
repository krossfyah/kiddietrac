@php
  /* "Starting in 30 minutes" (2026-11-04, App\Support\SalesMeetings::email). The booking emails' design (navy
     masthead, teal, warm white), short, and dark-mode friendly: a real dark palette for prefers-color-scheme and
     Outlook.com ([data-ogsc]); the masthead is navy in both, so the white logo needs no swap.
     $d: first, host, subject, whenEt, time, minutes, joinUrl, location */
  $site = 'https://www.kiddietrac.com';
  $navy = '#0B1F3A'; $teal = '#1FA2A6'; $cream = '#F6F4EF'; $ink = '#0B1F3A'; $text = '#4A5568'; $muted = '#8A94A6'; $line = '#E7E3DA';
  $f = "font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;";
  $first = $d['first'] ? e($d['first']) : '';
  $with = $d['host'] ? ' with ' . e($d['host']) . ' from KiddieTrac' : ' with KiddieTrac';
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>Your meeting starts in 30 minutes</title>
<style>
  body { margin:0; padding:0; background:{{ $cream }}; }
  a { color:{{ $teal }}; }
  @media (max-width:620px) { .wrap { width:100% !important; } .pad { padding-left:22px !important; padding-right:22px !important; } .hero-title { font-size:26px !important; } }
  @media (prefers-color-scheme: dark) {
    body, .bg { background:#0A1424 !important; }
    .sheet { background:#13213A !important; }
    .tx { color:#C9D4E3 !important; } .tk { color:#FFFFFF !important; } .mu { color:#8FA3BC !important; }
    .foot { background:#0A1424 !important; border-color:#22324F !important; }
    .foot .kt-social-lead { color:#C9D4E3 !important; }
  }
  [data-ogsc] .tx, [data-ogsc] .kt-social-lead { color:#C9D4E3 !important; } [data-ogsc] .tk { color:#FFFFFF !important; } [data-ogsc] .mu { color:#8FA3BC !important; }
  [data-ogsb] .bg, [data-ogsb] .foot { background:#0A1424 !important; } [data-ogsb] .sheet { background:#13213A !important; }
</style>
</head>
<body class="bg" style="margin:0;padding:0;background:{{ $cream }};">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">Starting at {{ $d['time'] }}{{ $d['joinUrl'] ? ' · join with the button inside' : '' }}&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" class="bg" width="100%" cellpadding="0" cellspacing="0" bgcolor="{{ $cream }}" style="background:{{ $cream }};">
<tr><td align="center" style="padding:28px 12px 40px;">
<table role="presentation" class="wrap sheet" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#FFFFFF;">

  <tr><td class="pad" bgcolor="{{ $navy }}" style="background:{{ $navy }};padding:22px 32px 6px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
      <td><a href="{{ $site }}"><img src="{{ $site }}/email-logo-v4.png" width="170" height="80" alt="KiddieTrac" style="display:block;border:0;width:170px;height:80px;"></a></td>
      <td align="right" style="{!! $f !!}font-size:13px;color:#C9D4E3;"><a href="tel:18554009996" style="color:#FFFFFF !important;text-decoration:none;font-weight:700;">1-855-400-9996</a><br><a href="mailto:sales@kiddietrac.com" style="color:#C9D4E3 !important;text-decoration:none;font-size:12px;line-height:20px;">Sales@kiddietrac.com</a><br><a href="mailto:info@kiddietrac.com" style="color:#C9D4E3 !important;text-decoration:none;font-size:12px;line-height:20px;">Info@kiddietrac.com</a></td>
    </tr></table>
    <div style="height:1px;line-height:1px;background:rgba(255,255,255,.12);margin-top:18px;">&nbsp;</div>
  </td></tr>

  <tr><td class="pad" bgcolor="{{ $navy }}" style="background:{{ $navy }};padding:30px 32px 38px;">
    <div style="{!! $f !!}font-size:11px;font-weight:700;letter-spacing:2.4px;text-transform:uppercase;color:#7FD3D5;">Starting in 30 minutes</div>
    <div class="hero-title" style="{!! $f !!}margin:14px 0 0;font-size:30px;line-height:1.2;font-weight:700;letter-spacing:-.6px;color:#FFFFFF;">{!! $first ? $first . ', your' : 'Your' !!} meeting{!! $with !!} is almost here.</div>
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:24px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);border-radius:10px;" width="100%"><tr><td style="padding:18px 20px;">
      <div style="{!! $f !!}font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#7FD3D5;">{{ $d['subject'] }}@if ($d['minutes'] > 0) &middot; {{ $d['minutes'] }} minutes @endif</div>
      <div style="{!! $f !!}margin-top:8px;font-size:20px;line-height:1.35;font-weight:700;color:#FFFFFF;">{{ $d['whenEt'] }}</div>
      <div style="{!! $f !!}margin-top:10px;font-size:14px;color:#C9D4E3;">{!! $d['joinUrl'] ? '&#127909; Microsoft Teams video call' : ($d['location'] ? '&#128205; ' . e($d['location']) : '&#128197; Details are in your calendar invitation') !!}</div>
    </td></tr></table>
    @if ($d['joinUrl'])
      <div style="margin:26px 0 0;"><a href="{{ $d['joinUrl'] }}" style="display:inline-block;background:{{ $teal }};color:#FFFFFF !important;text-decoration:none;font-weight:700;font-size:16px;padding:16px 34px;border-radius:6px;{!! $f !!}">Join the Teams meeting</a></div>
      <div style="{!! $f !!}margin-top:12px;font-size:12.5px;line-height:1.6;color:#8FA3BC;">No Teams app needed &mdash; the link opens in your browser. Join a few minutes early to test your camera and microphone.</div>
    @endif
  </td></tr>

  <tr><td class="pad sheet" bgcolor="#FFFFFF" style="background:#FFFFFF;padding:30px 32px 34px;">
    <div class="tx" style="{!! $f !!}font-size:15px;line-height:1.7;color:{{ $text }};">Running late or need another time? Reply to this email or call <strong class="tk" style="color:{{ $ink }};">1-855-400-9996</strong> and we&rsquo;ll sort it out.</div>
    <div class="tx" style="{!! $f !!}margin-top:18px;font-size:15px;line-height:1.7;color:{{ $text }};">See you soon,<br><span class="tk" style="color:{{ $ink }};font-weight:700;">{{ $d['host'] ?: 'The KiddieTrac team' }}</span>@if ($d['host'])<br><span class="mu" style="font-size:13.5px;color:{{ $muted }};">KiddieTrac Sales</span>@endif</div>
  </td></tr>

  <tr><td class="pad foot" bgcolor="{{ $cream }}" style="background:{{ $cream }};padding:24px 32px 26px;border-top:1px solid {{ $line }};{!! $f !!}font-size:12px;line-height:1.75;color:{{ $muted }};">
    {{-- Follow us, inside the footer (2026-11-04: the listener's strip below the frame was lost in dark mode). --}}
    <div style="margin:0 0 14px;">{!! \App\Support\SocialLinks::block(\App\Support\SocialLinks::row($muted, $f, 'Follow us', $muted, 'left')) !!}</div>
    <span class="mu">KiddieTrac &middot; Smart Childcare Management Platform<br>
    <a href="tel:18554009996" style="color:{{ $muted }} !important;">1-855-400-9996</a> &nbsp;&middot;&nbsp; <a href="mailto:sales@kiddietrac.com" style="color:{{ $muted }} !important;">sales@kiddietrac.com</a> &nbsp;&middot;&nbsp; <a href="{{ $site }}" style="color:{{ $muted }} !important;">kiddietrac.com</a><br>
    <a href="{{ $site }}/privacy/" style="color:{{ $muted }} !important;">Privacy</a> &nbsp;&middot;&nbsp; <a href="{{ $site }}/terms/" style="color:{{ $muted }} !important;">Terms</a><br>
    You&rsquo;re receiving this because you have a meeting booked with KiddieTrac.</span>
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
