@php
  /* Website meeting emails to the VISITOR (2026-10-23, App\Support\BookingMail).
     Same standalone design as the lead welcome (navy masthead, transparent white/teal logo,
     teal accent, warm white): Anthony approved that look.
     $kind: requested | confirmed | modified | declined | cancelled
     $d: first, typeName, minutes, whenEt, whenLocal, viaPhone, phone, teamsUrl, agenda[], prepare[],
         host, hostTitle, note, reason, oldWhenEt, gcal, book */
  $site = 'https://www.kiddietrac.com';
  $navy = '#0B1F3A'; $teal = '#1FA2A6'; $cream = '#F6F4EF'; $ink = '#0B1F3A'; $text = '#4A5568'; $muted = '#8A94A6'; $line = '#E7E3DA';
  $f = "font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;";
  $btn = 'display:inline-block;background:' . $teal . ';color:#FFFFFF !important;text-decoration:none;font-weight:700;font-size:16px;letter-spacing:.2px;padding:16px 34px;border-radius:6px;' . $f;
  $btn2 = 'display:inline-block;background:#FFFFFF;color:' . $ink . ' !important;text-decoration:none;font-weight:700;font-size:13.5px;padding:10px 16px;border-radius:6px;border:1px solid ' . $line . ';' . $f;
  $eyebrow = fn ($t, $c = null) => '<div style="font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:' . ($c ?: $teal) . ';' . $f . '">' . $t . '</div>';
  $h2 = fn ($t) => '<div style="margin:10px 0 0;font-size:24px;line-height:1.25;font-weight:700;letter-spacing:-.4px;color:' . $ink . ';' . $f . '">' . $t . '</div>';
  $p = fn ($t, $extra = '') => '<div style="margin:12px 0 0;font-size:15px;line-height:1.7;color:' . $text . ';' . $f . $extra . '">' . $t . '</div>';
  $row = fn ($k, $v) => '<tr><td style="padding:9px 18px 9px 0;vertical-align:top;white-space:nowrap;font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:' . $muted . ';' . $f . '">' . $k . '</td>'
      . '<td style="padding:9px 0;vertical-align:top;font-size:15px;line-height:1.5;color:' . $ink . ';font-weight:600;' . $f . '">' . $v . '</td></tr>';
  $list = function ($items) use ($teal, $text, $line, $f) {
      $o = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
      foreach ($items as $i => $it) {
          $o .= '<tr><td width="34" style="vertical-align:top;padding:12px 0;border-top:1px solid ' . $line . ';font-size:20px;line-height:1.2;font-weight:300;color:' . $teal . ';' . $f . '">' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . '</td>'
              . '<td style="vertical-align:top;padding:12px 0;border-top:1px solid ' . $line . ';font-size:14.5px;line-height:1.6;color:' . $text . ';' . $f . '">' . $it . '</td></tr>';
      }
      return $o . '</table>';
  };
  $section = fn ($inner, $bg = '#FFFFFF', $pad = '36px 32px') => '<tr><td class="pad" bgcolor="' . $bg . '" style="background:' . $bg . ';padding:' . $pad . ';">' . $inner . '</td></tr>';

  // Photographs from kiddietrac.com, the same ones the lead welcome uses (2026-10-23: "add any
  // images to the emails to make this pop even further").
  $photo = fn ($file, $alt, $extra = '') => '<img src="https://www.kiddietrac.com/images/life/' . $file . '" width="536" alt="' . e($alt) . '" style="display:block;width:100%;height:auto;border:0;border-radius:10px;' . $extra . '">';
  $kind = $kind ?? 'confirmed';
  $first = $d['first'] ? e($d['first']) : '';
  $isGo = in_array($kind, ['confirmed', 'modified'], true);
  $isRun = in_array($kind, ['runup', 'tomorrow', 'today'], true);
  $film = $d['film'] ?? null;
  $hero = [
      'requested' => ['We&rsquo;ve got your request', ($first ? $first . ', thanks' : 'Thanks') . ' &mdash; we&rsquo;ll confirm your time shortly.'],
      'confirmed' => ['You&rsquo;re confirmed', ($first ? $first . ', your' : 'Your') . ' ' . e(strtolower($d['typeName'])) . ' is booked.'],
      'modified'  => ['New time confirmed', ($first ? $first . ', we&rsquo;ve' : 'We&rsquo;ve') . ' moved your ' . e(strtolower($d['typeName'])) . '.'],
      'declined'  => ['About your request', ($first ? $first . ', we' : 'We') . ' can&rsquo;t make that time work.'],
      'cancelled' => ['Meeting cancelled', ($first ? $first . ', your' : 'Your') . ' ' . e(strtolower($d['typeName'])) . ' has been cancelled.'],
      'intake'    => ['Before we meet', ($first ? $first . ', help' : 'Help') . ' us prepare for your ' . e(strtolower($d['typeName'])) . '.'],
      'runup'     => [$film ? $film['eyebrow'] : 'Before we meet', $film ? $film['headline'] : ''],
      'tomorrow'  => ['See you tomorrow', ($first ? $first . ', we&rsquo;re' : 'We&rsquo;re') . ' all set for your ' . e(strtolower($d['typeName'])) . '.'],
      'today'     => ['See you today', ($first ? $first . ', we&rsquo;re' : 'We&rsquo;re') . ' looking forward to meeting you.'],
  ][$kind];
  $pre = [
      'requested' => 'Your ' . strtolower($d['typeName']) . ' request for ' . $d['whenEt'] . ' — we\'ll confirm shortly.',
      'confirmed' => strip_tags($d['typeName']) . ' · ' . $d['whenEt'] . ($d['viaPhone'] ? ' · we\'ll call you' : ' · Microsoft Teams link inside'),
      'modified'  => 'New time: ' . $d['whenEt'] . ($d['viaPhone'] ? '' : ' · Teams link inside'),
      'declined'  => 'Please choose another time that suits you.',
      'cancelled' => 'Book another time whenever it suits you.',
      'intake'    => 'A few minutes about your program, so we come to ' . $d['whenEt'] . ' ready for you.',
      'runup'     => $film ? html_entity_decode(strip_tags($film['title'] . ' — a ' . $film['length'] . ' film before our meeting on ' . $d['whenEt'])) : '',
      'tomorrow'  => 'Tomorrow: ' . $d['whenEt'] . ($d['viaPhone'] ? ' · we\'ll call you' : ' · your Teams link is inside'),
      'today'     => 'Today: ' . $d['whenEt'] . ($d['viaPhone'] ? ' · we\'ll call you' : ' · join with the button inside'),
  ][$kind];
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ strip_tags(html_entity_decode($hero[0])) }}</title>
<style>
  body { margin:0; padding:0; background:{{ $cream }}; }
  a { color:{{ $teal }}; }
  @media (max-width:620px) {
    .wrap { width:100% !important; }
    .pad { padding-left:22px !important; padding-right:22px !important; }
    .hero-title { font-size:28px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background:{{ $cream }};">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{{ $pre }}&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="{{ $cream }}" style="background:{{ $cream }};">
<tr><td align="center" style="padding:28px 12px 40px;">
<table role="presentation" class="wrap" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#FFFFFF;">

  <tr><td class="pad" bgcolor="{{ $navy }}" style="background:{{ $navy }};padding:22px 32px 6px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
      <td><a href="{{ $site }}"><img src="{{ $site }}/email-logo-v4.png" width="170" height="80" alt="KiddieTrac — Smart Childcare Management Platform" style="display:block;border:0;width:170px;height:80px;"></a></td>
      <td align="right" style="{!! $f !!}font-size:13px;color:#C9D4E3;"><a href="tel:18554009996" style="color:#FFFFFF !important;text-decoration:none;font-weight:700;">1-855-400-9996</a></td>
    </tr></table>
    <div style="height:1px;line-height:1px;background:rgba(255,255,255,.12);margin-top:18px;">&nbsp;</div>
  </td></tr>

  <tr><td class="pad" bgcolor="{{ $navy }}" style="background:{{ $navy }};padding:30px 32px 38px;">
    <div style="{!! $f !!}font-size:11px;font-weight:700;letter-spacing:2.4px;text-transform:uppercase;color:#7FD3D5;">{!! $hero[0] !!}</div>
    <div class="hero-title" style="{!! $f !!}margin:14px 0 0;font-size:32px;line-height:1.18;font-weight:700;letter-spacing:-.7px;color:#FFFFFF;">{!! $hero[1] !!}</div>
    @if ($kind !== 'declined' && $kind !== 'cancelled')
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:24px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);border-radius:10px;" width="100%"><tr><td style="padding:18px 20px;">
      <div style="{!! $f !!}font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#7FD3D5;">{{ $d['typeName'] }} &middot; {{ $d['minutes'] }} minutes</div>
      <div style="{!! $f !!}margin-top:8px;font-size:20px;line-height:1.35;font-weight:700;color:#FFFFFF;">{{ $d['whenEt'] }}</div>
      @if ($d['whenLocal'])<div style="{!! $f !!}margin-top:4px;font-size:14px;color:#C9D4E3;">{{ $d['whenLocal'] }}</div>@endif
      @if ($kind === 'modified' && $d['oldWhenEt'])<div style="{!! $f !!}margin-top:8px;font-size:13px;color:#8FA3BC;">Was: <span style="text-decoration:line-through;">{{ $d['oldWhenEt'] }}</span></div>@endif
      <div style="{!! $f !!}margin-top:10px;font-size:14px;color:#C9D4E3;">{!! $d['viaPhone'] ? '&#128222; We&rsquo;ll call you at <strong style="color:#FFFFFF;">' . e($d['phone']) . '</strong>' : '&#127909; Microsoft Teams video call' !!}</div>
    </td></tr></table>
    @endif
    @if (($isGo || $kind === 'tomorrow' || $kind === 'today') && ! $d['viaPhone'] && $d['teamsUrl'])
      <div style="margin:26px 0 0;"><a href="{{ $d['teamsUrl'] }}" style="{!! $btn !!}">Join the Teams meeting</a></div>
      <div style="{!! $f !!}margin-top:12px;font-size:12.5px;line-height:1.6;color:#8FA3BC;">No Teams app needed &mdash; the link opens in your browser. Join a few minutes early to test your camera and microphone.</div>
    @endif
  </td></tr>

  @if ($d['proposal'] && in_array($kind, ['confirmed', 'modified', 'intake', 'runup', 'tomorrow', 'today'], true))
  {{-- THE INTAKE FORM IS PART OF BEING READY (2026-10-23, Anthony: "ask them again when the
       meeting is booked to fill this as a requirement to understanding their business"). --}}
  @if (empty($d['intakeDone']))
  <tr><td class="pad" bgcolor="#FFFFFF" style="background:#FFFFFF;padding:30px 32px 6px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:2px solid {{ $teal }};border-radius:12px;"><tr><td style="padding:22px 22px 24px;">
      {!! $eyebrow($kind === 'intake' ? 'One thing before we meet' : 'Action needed before we meet', '#B45309') !!}
      <div style="{!! $f !!}margin:8px 0 0;font-size:21px;line-height:1.3;font-weight:700;color:{{ $ink }};">Tell us about your program</div>
      <div style="{!! $f !!}margin:10px 0 0;font-size:15px;line-height:1.7;color:{{ $text }};">To make the most of our time together, we ask every program to complete a short profile before the meeting: your locations, children and team, how you work today and what matters most. It lets us come prepared with answers &mdash; and shape a proposal around your business.</div>
      <div style="margin:18px 0 0;"><a href="{{ $d['intakeUrl'] }}" style="{!! $btn !!}">Complete my program profile</a></div>
      <div style="{!! $f !!}margin:10px 0 0;font-size:12.5px;color:{{ $muted }};">About five minutes &middot; secure KiddieTrac form &middot; you can update it any time before we meet</div>
    </td></tr></table>
  </td></tr>
  @else
  <tr><td class="pad" bgcolor="#FFFFFF" style="background:#FFFFFF;padding:24px 32px 0;">
    <div style="{!! $f !!}padding:12px 16px;border-radius:10px;background:#ECFDF5;color:#065F46;font-size:14px;font-weight:700;">&#10003; Thank you &mdash; we have your program details. <a href="{{ $d['intakeUrl'] }}" style="color:#065F46 !important;font-weight:700;">Update them</a></div>
  </td></tr>
  @endif
  @endif

  @if ($kind === 'intake')
  {!! $section('<div style="margin:0 0 18px;">' . $photo('director-laptop-800.jpg', 'A childcare director at her laptop') . '</div>'
      . $eyebrow('Why we ask') . $h2('So our time together is about you')
      . $p('Every program is different &mdash; a home child care agency runs nothing like a three-centre daycare. Your answers tell us which parts of KiddieTrac to show you, what to set up as a sample, and how to shape your proposal.')
      . $p('Questions before we meet? Reply to this email or call <strong style="color:' . $ink . ';">1-855-400-9996</strong>.'), '#FFFFFF', '30px 32px 34px') !!}
  @endif

  @if ($kind === 'runup' && $film)
  {{-- ONE FILM, ITS OWN STORY (2026-10-26). The poster has the play button and length baked in: Outlook
       ignores overlays. Every part links to the film's share page on kiddietrac.com. --}}
  <tr><td class="pad" bgcolor="#FFFFFF" style="background:#FFFFFF;padding:34px 32px 8px;">
    {!! $p($film['intro'], 'margin-top:0;font-size:16px;') !!}
    <div style="margin:20px 0 0;"><a href="{{ $film['url'] }}" style="display:block;text-decoration:none;">
      <img src="{{ $film['poster'] }}" width="536" alt="Play the {{ html_entity_decode(strip_tags($film['title'])) }} film" style="display:block;width:100%;height:auto;border:0;border-radius:12px;box-shadow:0 12px 30px -14px rgba(11,31,58,.45);">
    </a></div>
    <div style="margin:16px 0 0;"><a href="{{ $film['url'] }}" style="{!! $btn !!}">&#9654;&nbsp; Watch the {{ $film['length'] }} film</a></div>
  </td></tr>
  <tr><td class="pad" bgcolor="{{ $cream }}" style="background:{{ $cream }};padding:32px 32px 30px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
      @if ($film['mark'])<td width="68" style="vertical-align:middle;padding-right:14px;"><img src="{{ $film['mark'] }}" width="56" height="56" alt="{{ html_entity_decode(strip_tags($film['title'])) }}" style="display:block;border:0;width:56px;height:56px;"></td>@endif
      <td style="vertical-align:middle;">{!! $eyebrow($film['block']) !!}<div style="{!! $f !!}margin-top:6px;font-size:21px;line-height:1.25;font-weight:700;color:{{ $ink }};">{!! $film['title'] !!}</div></td>
    </tr></table>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:14px;">
    @foreach ($film['points'] as $pt)
      <tr><td width="40" style="vertical-align:top;padding:13px 0;border-top:1px solid {{ $line }};"><div style="{!! $f !!}width:28px;height:28px;line-height:28px;text-align:center;border-radius:14px;background:{{ $navy }};color:#FFFFFF;font-size:{{ is_numeric($pt[0]) ? '13px' : '15px' }};font-weight:700;">{!! $pt[0] !!}</div></td>
        <td style="vertical-align:top;padding:13px 0;border-top:1px solid {{ $line }};{!! $f !!}"><div style="font-size:15px;font-weight:700;color:{{ $ink }};">{!! $pt[1] !!}</div><div style="margin-top:3px;font-size:14.5px;line-height:1.6;color:{{ $text }};">{!! $pt[2] !!}</div></td></tr>
    @endforeach
    </table>
    @if (! empty($film['foot']))<div style="{!! $f !!}margin-top:10px;font-size:13.5px;line-height:1.6;color:{{ $muted }};">{!! $film['foot'] !!}</div>@endif
  </td></tr>
  {!! $section($photo($film['photo'], html_entity_decode($film['photoAlt']))
      . $p('We&rsquo;ll show you all of this live, in your own setup, at our meeting on <strong style="color:' . $ink . ';">' . e($d['whenEt']) . '</strong>. Questions before then? Just reply to this email.', 'margin-top:18px;'), '#FFFFFF', '32px 32px 30px') !!}
  @endif

  @if ($kind === 'today')
  {!! $section($eyebrow('Before you join') . '<div style="margin-top:6px;">' . $list($d['prepare']) . '</div>'
      . $p('Running late or need another time? Reply to this email or call <strong style="color:' . $ink . ';">1-855-400-9996</strong>.'), '#FFFFFF', '32px 32px 28px') !!}
  {!! $section($photo('reading-together-800.jpg', 'An educator reading with children'), '#FFFFFF', '0 32px 30px') !!}
  @endif

  @if ($d['note'])
  {!! $section($eyebrow('A note from ' . e($d['host'])) . $p(nl2br(e($d['note'])), 'margin-top:10px;font-style:italic;color:' . $ink . ';'), '#FFFFFF', '30px 32px 6px') !!}
  @endif


  @if (! empty($d['video']) && in_array($kind, ['requested', 'confirmed', 'modified'], true))
  {{-- THE VALUE FILM (2026-10-23): a poster that plays the cartoon on its watch page. Off until
       Anthony approves it (PlatformSettings sales.booking.value_video). --}}
  <tr><td class="pad" bgcolor="{{ $cream }}" style="background:{{ $cream }};padding:36px 32px 34px;">
    {!! $eyebrow($kind === 'requested' ? 'While you wait' : 'Before we meet') !!}
    {!! $h2('See the real value of KiddieTrac') !!}
    {!! $p('A ' . e($d['video']['length'] ?? '90-second') . ' film: the hours a program spends on sign-in sheets, invoices and paperwork every week &mdash; and what happens when that time goes back to the children.') !!}
    <div style="margin:18px 0 0;"><a href="{{ $d['video']['url'] }}" style="display:block;position:relative;text-decoration:none;">
      <img src="{{ $d['video']['poster'] }}" width="536" alt="Watch: the real value of KiddieTrac" style="display:block;width:100%;height:auto;border:0;border-radius:10px;">
    </a></div>
    <div style="margin:14px 0 0;"><a href="{{ $d['video']['url'] }}" style="{!! $btn2 !!}">&#9654;&nbsp; Watch the film</a></div>
  </td></tr>
  @endif

  @if ($kind === 'requested')
  {!! $section('<div style="margin:0 0 22px;">' . $photo('reading-together-800.jpg', 'An educator reading with children') . '</div>'
      . $eyebrow('What happens next') . $h2('We&rsquo;ll confirm by email')
      . $p('Our team is reviewing your request now. Within one business day you&rsquo;ll receive a confirmation with your Microsoft Teams link (or a confirmed call time), what we&rsquo;ll cover and how to prepare.')
      . $p('If that time no longer suits you, just reply to this email or call us at <strong style="color:' . $ink . ';">1-855-400-9996</strong>.')
      . ($d['proposal'] ? '<div style="margin:22px 0 0;padding:18px 20px;border:1px solid ' . $line . ';border-radius:10px;background:' . $cream . ';">'
          . '<div style="font-size:16px;font-weight:700;color:' . $ink . ';' . $f . '">Help us prepare for you</div>'
          . '<div style="margin:6px 0 0;font-size:14px;line-height:1.6;color:' . $text . ';' . $f . '">Tell us about your program in a short form &mdash; your locations, families, team and what matters most &mdash; so our conversation and your proposal fit from the start.</div>'
          . '<div style="margin:14px 0 0;"><a href="' . e($d['intakeUrl']) . '" style="' . $btn . 'font-size:14.5px;padding:12px 22px;">Tell us about your program</a></div></div>' : ''), '#FFFFFF', '38px 32px 34px') !!}
  @endif

  @if ($isGo || $kind === 'tomorrow')
  {!! $section($eyebrow('What to expect') . $h2('Your ' . e($d['minutes']) . ' minutes, planned') . '<div style="margin-top:14px;">' . $list($d['agenda']) . '</div>', '#FFFFFF', '38px 32px 26px') !!}
  {!! $section($eyebrow('How to prepare') . '<div style="margin-top:6px;">' . $list($d['prepare']) . '</div>', $cream, '32px 32px 28px') !!}
  {!! $section($eyebrow('Who you&rsquo;ll meet')
      . '<div style="margin:10px 0 0;font-size:18px;font-weight:700;color:' . $ink . ';' . $f . '">' . e($d['host']) . '</div>'
      . '<div style="margin:2px 0 0;font-size:14px;color:' . $text . ';' . $f . '">' . e($d['hostTitle']) . ' &middot; KiddieTrac</div>'
      . $p('Need to change the time? Reply to this email or call <strong style="color:' . $ink . ';">1-855-400-9996</strong> and we&rsquo;ll find another slot.')
      . '<div style="margin:18px 0 0;font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:' . $muted . ';' . $f . '">Add it to your calendar</div>'
      . '<div style="margin:8px 0 0;line-height:2.9;">' . collect($d['calLinks'])->map(fn ($l) => '<a href="' . e($l[1]) . '" title="Add to ' . e($l[0]) . '" style="' . $btn2 . 'margin:0 6px 0 0;padding:8px 12px;white-space:nowrap;">'
          . '<img src="' . e($l[2]) . '" width="' . $l[3] . '" height="' . $l[4] . '" alt="' . e($l[0]) . '" style="display:inline-block;vertical-align:middle;border:0;' . ($l[5] ? 'margin-right:7px;' : '') . '">'
          . ($l[5] ? '<span style="vertical-align:middle;">' . e($l[0]) . '</span>' : '') . '</a>')->implode('') . '</div>'
      . ($kind === 'tomorrow' ? '' : '<div style="margin:6px 0 0;font-size:12.5px;color:' . $muted . ';' . $f . '">The invitation is attached too &mdash; Gmail and Outlook show their own &ldquo;Add to calendar&rdquo; button above this email.</div>'), '#FFFFFF', '34px 32px 38px') !!}
  @endif


  @if ($kind === 'tomorrow' && ! empty($d['films']))
  {{-- THE FILMS, ONE MORE TIME (2026-10-26): anything not watched yet is a tap away. --}}
  <tr><td class="pad" bgcolor="{{ $cream }}" style="background:{{ $cream }};padding:32px 32px 26px;">
    {!! $eyebrow('Before tomorrow') !!}{!! $h2('A few short films, if you have a minute') !!}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px;"><tr>
    @foreach (array_slice($d['films'], 0, 4) as $i => $fm)
      @if ($i === 2)</tr><tr>@endif
      <td width="50%" style="vertical-align:top;padding:{{ $i % 2 ? '0 0 16px 8px' : '0 8px 16px 0' }};">
        <a href="{{ $fm['url'] }}" style="text-decoration:none;"><img src="{{ $fm['poster'] }}" width="260" alt="Play the {{ $fm['title'] }} film" style="display:block;width:100%;height:auto;border:0;border-radius:8px;"></a>
        <div style="{!! $f !!}margin-top:8px;font-size:14px;font-weight:700;"><a href="{{ $fm['url'] }}" style="color:{{ $ink }} !important;text-decoration:none;">{{ $fm['title'] }}</a></div>
      </td>
    @endforeach
    </tr></table>
  </td></tr>
  @endif

  @if ($isGo || $kind === 'requested')
  {{-- WHY PROGRAMS CHOOSE KIDDIETRAC (2026-10-23, Anthony: "add more content ... to encourage a
       sale and close a deal"). Only what the website itself says. --}}
  {!! $section($eyebrow('Why programs choose KiddieTrac') . $h2('More time with the children. Less time on paperwork.')
      . '<div style="margin:18px 0 0;"><img src="https://www.kiddietrac.com/demo-assets/email-dashboard-v2.jpg" width="536" alt="The KiddieTrac dashboard" style="display:block;width:100%;height:auto;border:0;border-radius:10px;box-shadow:0 10px 30px -12px rgba(11,31,58,.35);"></div>'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px;">'
      . collect([
          ['Hours back every week', 'Attendance, invoices, parent updates and compliance records happen as the day happens &mdash; not after closing.'],
          ['One-Tap check-in', 'Families sign in and out in seconds; attendance and ratios are always current.'],
          ['Billing that runs itself', 'Invoices, autopay and subsidy tracking, so you stop chasing balances.'],
          ['Families in the loop', 'Daily reports, photos and messages in the parent app &mdash; fewer calls, happier parents.'],
          ['Every child accounted for', 'Safe Arrival checks on any child who hasn&rsquo;t arrived and alerts your team.'],
          ['Help at every step', 'We set it up with you and bring your families and staff on board.'],
      ])->map(fn ($r) => '<tr><td width="26" style="vertical-align:top;padding:12px 0;border-top:1px solid ' . $line . ';font-size:15px;line-height:1.5;color:' . $teal . ';font-weight:700;' . $f . '">&#10003;</td>'
          . '<td style="vertical-align:top;padding:12px 0;border-top:1px solid ' . $line . ';' . $f . '"><span style="font-size:15px;font-weight:700;color:' . $ink . ';">' . $r[0] . '</span>'
          . '<span style="font-size:14.5px;line-height:1.6;color:' . $text . ';"> &mdash; ' . $r[1] . '</span></td></tr>')->implode('')
      . '</table>', '#FFFFFF', '38px 32px 30px') !!}
  @endif

  @if ($isGo && $d['proposal'])
  <tr><td class="pad" bgcolor="#FFFFFF" style="background:#FFFFFF;padding:0 32px 30px;">{!! $photo('walking-together-800.jpg', 'Two children walking hand in hand') !!}</td></tr>
  @endif
  @if ($isGo || $kind === 'tomorrow')
  {{-- AFTER THE CALL: the next step is easy, and free. --}}
  <tr><td class="pad" bgcolor="{{ $navy }}" align="center" style="background:{{ $navy }};padding:44px 32px;text-align:center;">
    <div style="{!! $f !!}font-size:11px;font-weight:700;letter-spacing:2.4px;text-transform:uppercase;color:#7FD3D5;">After our call</div>
    @if ($d['proposal'])
    <div style="{!! $f !!}margin:12px auto 0;font-size:26px;line-height:1.25;font-weight:700;letter-spacing:-.5px;color:#FFFFFF;max-width:470px;">A partnership proposal, built around your program</div>
    <div style="{!! $f !!}margin:14px auto 0;font-size:15px;line-height:1.7;color:#C9D4E3;max-width:470px;">Every program is different. After we meet, we&rsquo;ll prepare a proposal for you: the right setup for your locations, onboarding for your team and families, and how we&rsquo;ll work together as partners as you grow.</div>
    <div style="{!! $f !!}margin:16px auto 0;font-size:14px;line-height:1.6;color:#7FD3D5;max-width:470px;font-weight:700;">Tailored proposal &nbsp;&middot;&nbsp; Free data migration &nbsp;&middot;&nbsp; White-glove setup &nbsp;&middot;&nbsp; Live in as little as 14 days</div>
    <div style="{!! $f !!}margin:22px auto 0;font-size:15px;line-height:1.6;color:#FFFFFF;max-width:470px;font-weight:700;">Help us tailor it &mdash; tell us about your program before we meet.</div>
    <div style="margin:18px 0 0;"><a href="{{ $d['intakeUrl'] }}" style="{!! $btn !!}">Tell us about your program</a></div>
    <div style="{!! $f !!}margin:14px 0 0;font-size:13px;color:#8FA3BC;">A short, secure KiddieTrac form &mdash; about five minutes. Or simply bring your questions to the meeting.</div>
    @else
    <div style="{!! $f !!}margin:12px auto 0;font-size:26px;line-height:1.25;font-weight:700;letter-spacing:-.5px;color:#FFFFFF;max-width:460px;">Partner with us &mdash; we&rsquo;ll launch it with you</div>
    <div style="{!! $f !!}margin:14px auto 0;font-size:15px;line-height:1.7;color:#C9D4E3;max-width:460px;">A tailored proposal, free data migration and white-glove setup: we set up your rooms, bring your families and staff on board, and you&rsquo;re live in as little as 14 days.</div>
    <div style="margin:24px 0 0;"><a href="mailto:sales@kiddietrac.com?subject=Our%20KiddieTrac%20proposal" style="{!! $btn !!}">Get your proposal</a></div>
    <div style="{!! $f !!}margin:14px 0 0;font-size:13px;color:#8FA3BC;">Or simply bring your questions to the meeting &mdash; that&rsquo;s what it&rsquo;s for.</div>
    @endif
  </td></tr>
  @endif

  @if ($kind === 'declined' || $kind === 'cancelled')
  {!! $section(($d['reason'] ? $p(nl2br(e($d['reason'])), 'margin-top:0;color:' . $ink . ';') : '')
      . $p($kind === 'declined'
          ? 'We&rsquo;re sorry &mdash; we couldn&rsquo;t keep <strong style="color:' . $ink . ';">' . e($d['whenEt']) . '</strong> open for your ' . e(strtolower($d['typeName'])) . '. We&rsquo;d still love to meet you, so please choose another time that works for you.'
          : 'Your ' . e(strtolower($d['typeName'])) . ' on <strong style="color:' . $ink . ';">' . e($d['whenEt']) . '</strong> is cancelled. Whenever you&rsquo;re ready, pick a new time below.', $d['reason'] ? '' : 'margin-top:0;')
      . '<div style="margin:24px 0 0;"><a href="' . e($d['book']) . '" style="' . $btn . '">Choose another time</a></div>'
      . $p('Or reply to this email &mdash; we&rsquo;re happy to find something that fits.', 'font-size:14px;'), '#FFFFFF', '38px 32px 40px') !!}
  @endif

  @if ($isGo)
  {{-- Now on Google Play (2026-10-25) --}}
  {!! $section(\App\Support\PlayBadge::card($f, $ink, $text), '#FFFFFF', '34px 32px 6px') !!}
  @endif
  {!! $section($p('Warm regards,<br><span style="color:' . $ink . ';font-weight:700;">' . e($d['host'] ?: 'The KiddieTrac team') . '</span>' . ($d['host'] ? '<br><span style="font-size:13.5px;color:' . $muted . ';">KiddieTrac Sales</span>' : ''), 'margin-top:0;'), '#FFFFFF', '6px 32px 38px') !!}

  <tr><td class="pad" bgcolor="{{ $cream }}" style="background:{{ $cream }};padding:26px 32px 28px;border-top:1px solid {{ $line }};{!! $f !!}font-size:12px;line-height:1.75;color:{{ $muted }};">
    KiddieTrac &mdash; #1 in North America &middot; Smart Childcare Management Platform<br>
    <a href="tel:18554009996" style="color:{{ $muted }} !important;">1-855-400-9996</a> &nbsp;&middot;&nbsp; <a href="mailto:sales@kiddietrac.com" style="color:{{ $muted }} !important;">sales@kiddietrac.com</a> &nbsp;&middot;&nbsp; <a href="{{ $site }}" style="color:{{ $muted }} !important;">kiddietrac.com</a><br>
    You&rsquo;re receiving this because a meeting was requested at kiddietrac.com with this address.
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
