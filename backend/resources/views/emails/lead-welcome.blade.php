@php
  /* The lead welcome, third pass (2026-10-22). Anthony: "it looks cheap - full sweep".
     What read as cheap: emoji for icons, a pastel box around everything, too many
     colours. This version is a standalone email, not the generic system-email frame:
     real product screenshots and the site's own photographs, one accent colour (teal)
     on navy and warm white, generous space, few boxes.

     Email-safe: tables, inline styles, a bgcolor under every gradient, columns that are
     inline-blocks (side by side on a computer, stacked on a phone, with a media query
     to make them full width where the client honours it). Light scheme declared so mail
     apps do not invent a dark version. Every claim is one the website makes, "#1 in North America" included (the
     site's own headline; Anthony asked for it in the email, 2026-10-22). */
  $name = trim((string) ($recipientName ?? ''));
  $first = ($name !== '' && strtolower($name) !== 'there') ? e(explode(' ', $name)[0]) : '';
  $site = 'https://www.kiddietrac.com';
  $book = $site . '/?book=1';
  $trial = 'https://app.kiddietrac.com/signup.html';
  $film = $site . '/watch/a-day-with-kiddietrac/';
  $safe = $site . '/watch/safe-arrival/';
  $img = $site . '/images/life/';

  $navy = '#0B1F3A'; $teal = '#1FA2A6'; $cream = '#F6F4EF'; $ink = '#0B1F3A'; $text = '#4A5568'; $muted = '#8A94A6'; $line = '#E7E3DA';
  $f = "font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;";
  $btn = 'display:inline-block;background:' . $teal . ';color:#FFFFFF !important;text-decoration:none;font-weight:700;font-size:15px;letter-spacing:.2px;padding:15px 30px;border-radius:6px;' . $f;
  $eyebrow = function ($t) use ($teal, $f) {
      return '<div style="font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:' . $teal . ';' . $f . '">' . $t . '</div>';
  };
  $h2 = function ($t) use ($ink, $f) {
      return '<div style="margin:10px 0 0;font-size:26px;line-height:1.25;font-weight:700;letter-spacing:-.4px;color:' . $ink . ';' . $f . '">' . $t . '</div>';
  };
  $p = function ($t, $extra = '') use ($text, $f) {
      return '<div style="margin:12px 0 0;font-size:15px;line-height:1.7;color:' . $text . ';' . $f . $extra . '">' . $t . '</div>';
  };
  // Two columns: side by side on a computer, stacked on a phone.
  $cols = function ($left, $right, $gap = 24, $valign = 'middle') {
      $w = (int) ((536 - $gap) / 2);
      return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="font-size:0;">'
          . '<div class="col" style="display:inline-block;width:100%;max-width:' . $w . 'px;vertical-align:' . $valign . ';font-size:15px;">' . $left . '</div>'
          . '<div class="col-gap" style="display:inline-block;width:' . $gap . 'px;font-size:0;">&nbsp;</div>'
          . '<div class="col" style="display:inline-block;width:100%;max-width:' . $w . 'px;vertical-align:' . $valign . ';font-size:15px;">' . $right . '</div>'
          . '</td></tr></table>';
  };
  $photo = function ($file, $alt) use ($img) {
      return '<img src="' . $img . $file . '" width="256" alt="' . e($alt) . '" style="display:block;width:100%;height:auto;border:0;border-radius:8px;">';
  };
  $story = function ($label, $title, $body) use ($eyebrow, $ink, $text, $f) {
      return '<div style="padding:4px 0;">' . $eyebrow($label)
          . '<div style="margin:8px 0 0;font-size:20px;line-height:1.3;font-weight:700;color:' . $ink . ';' . $f . '">' . $title . '</div>'
          . '<div style="margin:8px 0 0;font-size:14.5px;line-height:1.65;color:' . $text . ';' . $f . '">' . $body . '</div></div>';
  };
  $feat = function ($title, $body) use ($teal, $ink, $text, $line, $f) {
      return '<tr><td width="26" style="vertical-align:top;padding:14px 0;border-top:1px solid ' . $line . ';font-size:15px;line-height:1.5;color:' . $teal . ';font-weight:700;' . $f . '">&#10003;</td>'
          . '<td style="vertical-align:top;padding:14px 0;border-top:1px solid ' . $line . ';' . $f . '"><span style="font-size:15px;font-weight:700;color:' . $ink . ';">' . $title . '</span>'
          . '<span style="font-size:14.5px;line-height:1.6;color:' . $text . ';"> &mdash; ' . $body . '</span></td></tr>';
  };
  $stepRow = function ($n, $title, $body) use ($teal, $ink, $text, $f) {
      return '<tr><td width="64" style="vertical-align:top;padding:14px 0;font-size:30px;line-height:1;font-weight:300;color:' . $teal . ';' . $f . '">' . $n . '</td>'
          . '<td style="vertical-align:top;padding:14px 0;' . $f . '"><div style="font-size:17px;font-weight:700;color:' . $ink . ';">' . $title . '</div>'
          . '<div style="margin-top:4px;font-size:14.5px;line-height:1.65;color:' . $text . ';">' . $body . '</div></td></tr>';
  };
  $section = function ($inner, $bg = '#FFFFFF', $pad = '44px 32px') {
      return '<tr><td class="pad" bgcolor="' . $bg . '" style="background:' . $bg . ';padding:' . $pad . ';">' . $inner . '</td></tr>';
  };
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>Welcome to KiddieTrac</title>
<style>
  body { margin:0; padding:0; background:{{ $cream }}; }
  a { color:{{ $teal }}; }
  @media (max-width:620px) {
    .wrap { width:100% !important; }
    .pad { padding-left:22px !important; padding-right:22px !important; }
    .col { max-width:100% !important; display:block !important; }
    .col-gap { display:block !important; width:100% !important; height:18px !important; }
    .hero-title { font-size:30px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background:{{ $cream }};">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{{ $first ? $first . ', your' : 'Your' }} one-minute look at KiddieTrac, and a time to talk whenever it suits you.&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="{{ $cream }}" style="background:{{ $cream }};">
<tr><td align="center" style="padding:28px 12px 40px;">
<table role="presentation" class="wrap" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#FFFFFF;">

  {{-- Masthead. Navy, running into the hero, with a TRANSPARENT white-and-teal logo
       (email-logo-v3.png). A navy logo on white vanished in dark mode, and one baked onto
       navy showed as a box when Outlook's dark mode lightened the band to slate
       (Anthony's phone, 2026-10-23). White and teal read on the navy and on the slate. --}}
  <tr><td class="pad" bgcolor="{{ $navy }}" style="background:{{ $navy }};padding:22px 32px 6px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
      <td><a href="{{ $site }}"><img src="{{ $site }}/email-logo-v4.png" width="170" height="80" alt="KiddieTrac — Smart Childcare Management Platform" style="display:block;border:0;width:170px;height:80px;"></a></td>
      <td align="right" style="{!! $f !!}font-size:13px;"><a href="{{ $book }}" style="color:#FFFFFF !important;text-decoration:none;font-weight:700;">Book a demo &rarr;</a></td>
    </tr></table>
    <div style="height:1px;line-height:1px;background:rgba(255,255,255,.12);margin-top:18px;">&nbsp;</div>
  </td></tr>

  {{-- Hero --}}
  <tr><td class="pad" bgcolor="{{ $navy }}" style="background:{{ $navy }};padding:30px 32px 40px;">
    <div style="{!! $f !!}font-size:11px;font-weight:700;letter-spacing:2.4px;text-transform:uppercase;color:#7FD3D5;">#1 in North America &nbsp;&middot;&nbsp; Smart Childcare Management Platform</div>
    <div class="hero-title" style="{!! $f !!}margin:14px 0 0;font-size:36px;line-height:1.15;font-weight:700;letter-spacing:-.8px;color:#FFFFFF;">
      {!! $first ? $first . ', here&rsquo;s a calmer, better-run program.' : 'A calmer, better-run childcare program starts here.' !!}
    </div>
    <div style="{!! $f !!}margin:16px 0 0;font-size:16px;line-height:1.7;color:#C9D4E3;max-width:470px;">
      Attendance, billing, parent communication and child safety &mdash; together in one platform, built for daycares and home child care agencies across North America.
    </div>
    <div style="margin:28px 0 0;"><a href="{{ $book }}" style="{!! $btn !!}">Book your walkthrough</a></div>
    <div style="{!! $f !!}margin:16px 0 0;font-size:13px;color:#8FA3BC;">Tailored proposal &nbsp;&middot;&nbsp; Free data migration &nbsp;&middot;&nbsp; Live in as little as 14 days</div>
  </td></tr>
  {{-- The product, sitting across the navy and the white. --}}
  <tr><td class="pad" bgcolor="{{ $navy }}" style="background:{{ $navy }};background-image:linear-gradient({{ $navy }} 0%,{{ $navy }} 55%,#FFFFFF 55%,#FFFFFF 100%);padding:0 32px;">
    <img src="{{ $site }}/demo-assets/email-dashboard-v2.jpg" width="536" alt="The KiddieTrac agency dashboard" style="display:block;width:100%;max-width:536px;height:auto;border:0;border-radius:8px;box-shadow:0 18px 40px rgba(11,31,58,.28);">
  </td></tr>

  {{-- A personal note --}}
  {!! $section(
      $p(($first ? 'Hi ' . $first . ',' : 'Hello,'), 'margin-top:0;color:' . $ink . ';font-weight:700;')
      . $p('Thank you for getting in touch. Running a childcare program means a hundred small things every day &mdash; and KiddieTrac exists to take care of as many of them as possible, so your team can spend its time with the children.')
      . $p('Here&rsquo;s a short look at how it works. When you&rsquo;re ready, we&rsquo;d love to show you around with your own program in mind.'),
      '#FFFFFF', '40px 32px 8px') !!}

  @if (! empty($webinar))
  {!! $section(
      '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="--kt-edge:' . $teal . ';"><tr><td style="padding:4px 0 4px 16px;">'
      . $eyebrow('Webinars') . $p('You&rsquo;re on the webinar list. When the first live sessions are scheduled, you&rsquo;ll hear first &mdash; with the topics, the dates and a link to save your seat.', 'margin-top:6px;')
      . '</td></tr></table>',
      '#FFFFFF', '24px 32px 0') !!}
  @endif

  {{-- The film --}}
  {!! $section(
      $eyebrow('One minute') . $h2('See a day with KiddieTrac')
      . '<div style="margin:18px 0 0;"><a href="' . $film . '"><img src="' . $site . '/videos/a-day-with-kiddietrac/og-play.jpg" width="536" alt="Watch: A Day with KiddieTrac (1 minute)" style="display:block;width:100%;height:auto;border:0;border-radius:8px;"></a></div>'
      . '<div style="margin:12px 0 0;' . $f . 'font-size:14px;"><a href="' . $film . '" style="color:' . $teal . ' !important;font-weight:700;text-decoration:none;">Watch the film &rarr;</a></div>',
      '#FFFFFF', '32px 32px 36px') !!}

  {{-- SAFE ARRIVAL, in its own branding (Anthony, 2026-10-23: "add to the welcome email our
       safe arrival feature as a highlight with the safe arrival branding"). The deep green
       is the film card's own background (#022C22), the shield is cut from that card, and the
       mint is the brand's #34D399. --}}
  <tr><td class="pad" bgcolor="#022C22" style="background:#022C22;background-image:linear-gradient(160deg,#022C22 0%,#064E3B 100%);padding:44px 32px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
      <td width="64" style="vertical-align:top;padding-right:16px;"><img src="{{ $site }}/videos/safe-arrival/email-shield.png" width="50" height="55" alt="Safe Arrival" style="display:block;border:0;width:50px;height:55px;"></td>
      <td style="vertical-align:middle;">
        <div style="{!! $f !!}font-size:11px;font-weight:700;letter-spacing:2.4px;text-transform:uppercase;color:#6EE7B7;">Safe Arrival</div>
        <div style="{!! $f !!}margin:6px 0 0;font-size:26px;line-height:1.2;font-weight:700;letter-spacing:-.4px;color:#FFFFFF;">Every child, accounted for.</div>
      </td>
    </tr></table>
    <div style="{!! $f !!}margin:16px 0 0;font-size:15.5px;line-height:1.7;color:#CDEBDF;">Every morning KiddieTrac checks that each child expected today has arrived. When one hasn&rsquo;t, it reaches the parents first, then the people in the room &mdash; and keeps following up until everyone knows the child is safe.</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px;">
      <tr><td width="26" style="{!! $f !!}vertical-align:top;padding:5px 0;font-size:15px;font-weight:700;color:#34D399;">&#10003;</td><td style="{!! $f !!}padding:5px 0;font-size:15px;line-height:1.55;color:#FFFFFF;">Only the children you&rsquo;re really expecting today</td></tr>
      <tr><td width="26" style="{!! $f !!}vertical-align:top;padding:5px 0;font-size:15px;font-weight:700;color:#34D399;">&#10003;</td><td style="{!! $f !!}padding:5px 0;font-size:15px;line-height:1.55;color:#FFFFFF;">Parents first, then the people in the room</td></tr>
      <tr><td width="26" style="{!! $f !!}vertical-align:top;padding:5px 0;font-size:15px;font-weight:700;color:#34D399;">&#10003;</td><td style="{!! $f !!}padding:5px 0;font-size:15px;line-height:1.55;color:#FFFFFF;">Every step kept as the day&rsquo;s record</td></tr>
    </table>
    <div style="margin:22px 0 0;"><a href="{{ $safe }}"><img src="{{ $site }}/videos/safe-arrival/og-play.jpg" width="536" alt="Watch: Safe Arrival, the film" style="display:block;width:100%;max-width:536px;height:auto;border:0;border-radius:8px;"></a></div>
    <div style="margin:22px 0 0;"><a href="{{ $safe }}" style="display:inline-block;background:#34D399;color:#022C22 !important;text-decoration:none;font-weight:700;font-size:15px;padding:14px 26px;border-radius:6px;{!! $f !!}">Watch the Safe Arrival film</a></div>
  </td></tr>

  {{-- Three stories, photo and words alternating --}}
  {!! $section(
      $eyebrow('Why programs choose KiddieTrac') . $h2('Less paperwork. Safer days. Happier families.')
      . '<div style="height:28px;line-height:28px;">&nbsp;</div>'
      . $cols($photo('reading-together-800.jpg', 'An educator reading with a child'),
              $story('Your team', 'More time with the children', 'One-Tap check-in, ratios that keep themselves, the daily log, schedules and the time clock in one place &mdash; so your educators spend the day with children, not paperwork.'))
      . '<div style="height:28px;line-height:28px;">&nbsp;</div>'
      . $cols($story('Families', 'Parents who feel part of the day', 'Daily reports, photos and messages arrive in the parent app &mdash; now on the App Store and Google Play &mdash; in English, French, Spanish or Hindi. Families feel close, and they talk about you.'),
              $photo('parent-baby-800.jpg', 'A parent with their child'))
      . '<div style="height:28px;line-height:28px;">&nbsp;</div>'
      . $cols($photo('director-laptop-800.jpg', 'A director at her laptop'),
              $story('Business', 'Billing that gets you paid', 'Invoices, CWELCC fees, autopay and online card payments, with QuickBooks sync. Fewer reminders to send, fewer fees to chase.')),
      '#FFFFFF', '40px 32px 44px') !!}

  {{-- Everything else, as a clean list --}}
  {!! $section(
      $eyebrow('All in one place') . $h2('Everything your program runs on')
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:18px;">'
      . $feat('Attendance &amp; One-Tap check-in', 'sign-in and sign-out in a tap, with ratios kept for you')
      . $feat('Billing &amp; payments', 'invoices, CWELCC, autopay, card payments and QuickBooks sync')
      . $feat('Parent app', 'daily reports, photos, messages and invoices in one place')
      . $feat('Staff &amp; payroll', 'schedules, time clock, substitutes, payslips and certifications, with KiddieTrac HR as an add-on')
      . $feat('Forms &amp; e-signatures', 'enrolment and consent forms, signed online')
      . $feat('Your brand', 'white-label apps carrying your own name and logo')
      . '</table>',
      $cream, '44px 32px') !!}

  {{-- Both kinds of care --}}
  {!! $section(
      $eyebrow('Made for how you run care') . $h2('Daycares and home child care agencies alike')
      . '<div style="height:24px;line-height:24px;">&nbsp;</div>'
      . $cols(
          $photo('nursery-room-800.jpg', 'A bright daycare room')
            . '<div style="margin:14px 0 0;' . $f . 'font-size:17px;font-weight:700;color:' . $ink . ';">Daycares &amp; multi-centre groups</div>'
            . '<div style="margin:6px 0 0;' . $f . 'font-size:14.5px;line-height:1.65;color:' . $text . ';">Rooms, ratios, waitlists and enrolment, with one view across every centre &mdash; and each director sees their own.</div>',
          $photo('home-provider-800.jpg', 'A home child care provider')
            . '<div style="margin:14px 0 0;' . $f . 'font-size:17px;font-weight:700;color:' . $ink . ';">Home child care agencies</div>'
            . '<div style="margin:6px 0 0;' . $f . 'font-size:14.5px;line-height:1.65;color:' . $text . ';">Every provider&rsquo;s home on one platform: attendance and daily reports from each home, home visits and agency billing.</div>', 24, 'top'),
      '#FFFFFF', '44px 32px') !!}

  {{-- Now on the App Store (2026-11-06) and Google Play (2026-10-25) --}}
  {!! $section(\App\Support\PlayBadge::card($f, $ink, $text), '#FFFFFF', '0 32px 36px') !!}

  {{-- Getting started --}}
  {!! $section(
      $eyebrow('Getting started') . $h2('Up and running, with us beside you')
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px;">'
      . $stepRow('01', 'A short walkthrough', 'A relaxed conversation about how your program runs, and KiddieTrac shown with your setup in mind.')
      . $stepRow('02', 'We bring you across', 'Your families, children and rooms move over with our help &mdash; you don&rsquo;t start from scratch.')
      . $stepRow('03', 'Your first easy day', 'Families download the app, your team checks in, and the day simply runs.')
      . '</table>'
      . '<div style="margin:22px 0 0;padding:18px 0 0;border-top:1px solid ' . $line . ';' . $f . 'font-size:14px;color:' . $text . ';">The KiddieTrac brochure is attached &mdash; or <a href="' . e($brochureUrl) . '" style="color:' . $teal . ' !important;font-weight:700;text-decoration:none;">download it here</a> to share with your team.</div>',
      '#FFFFFF', '8px 32px 44px') !!}

  {{-- The ask --}}
  <tr><td class="pad" bgcolor="{{ $navy }}" align="center" style="background:{{ $navy }};padding:52px 32px;text-align:center;">
    <div style="{!! $f !!}font-size:11px;font-weight:700;letter-spacing:2.4px;text-transform:uppercase;color:#7FD3D5;">Let&rsquo;s talk</div>
    <div style="{!! $f !!}margin:12px auto 0;font-size:28px;line-height:1.25;font-weight:700;letter-spacing:-.5px;color:#FFFFFF;max-width:440px;">See KiddieTrac with your program in mind</div>
    <div style="{!! $f !!}margin:14px auto 0;font-size:15.5px;line-height:1.7;color:#C9D4E3;max-width:440px;">Choose a time that suits you. We&rsquo;ll answer every question and show you exactly how it fits.</div>
    <div style="margin:28px 0 0;"><a href="{{ $book }}" style="{!! $btn !!}">Book a time with us</a></div>
    <div style="{!! $f !!}margin:18px 0 0;font-size:14px;color:#8FA3BC;">Partner with us: a tailored proposal, free data migration and white-glove setup &mdash; live in as little as 14 days. <a href="mailto:sales@kiddietrac.com?subject=Our%20KiddieTrac%20proposal" style="color:#FFFFFF !important;font-weight:700;">Get your proposal</a></div>
  </td></tr>

  {{-- Sign-off --}}
  {!! $section(
      $p('We&rsquo;re looking forward to meeting you.', 'margin-top:0;')
      . $p('Warm regards,<br><span style="color:' . $ink . ';font-weight:700;">The KiddieTrac team</span>')
      . $p('<span style="color:' . $ink . ';font-weight:700;">P.S.</span> Reply and tell us the one task that takes up most of your week &mdash; we&rsquo;ll show you how KiddieTrac takes it off your plate.', 'margin-top:20px;'),
      '#FFFFFF', '40px 32px 44px') !!}

  {{-- Footer --}}
  <tr><td class="pad" bgcolor="{{ $cream }}" style="background:{{ $cream }};padding:28px 32px 30px;border-top:1px solid {{ $line }};{!! $f !!}font-size:12px;line-height:1.75;color:{{ $muted }};">
    KiddieTrac &mdash; #1 in North America &middot; Smart Childcare Management Platform<br> <a href="tel:18554009996" style="color:{{ $muted }} !important;">1-855-400-9996</a> &nbsp;&middot;&nbsp; <a href="mailto:info@kiddietrac.com" style="color:{{ $muted }} !important;">info@kiddietrac.com</a><br>
    You&rsquo;re receiving this because this address contacted KiddieTrac or signed up at kiddietrac.com, and we&rsquo;ve added it to our updates list.
    <a href="{{ $unsubscribeUrl }}" style="color:{{ $muted }} !important;font-weight:700;">Unsubscribe</a> &nbsp;&middot;&nbsp;
    <a href="{{ $site }}/privacy" style="color:{{ $muted }} !important;">Privacy</a> &nbsp;&middot;&nbsp;
    <a href="{{ $site }}/terms" style="color:{{ $muted }} !important;">Terms</a>
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
