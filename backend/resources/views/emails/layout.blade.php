<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $title ?? 'Kiddietrac' }}</title>
<style>
  /* ── Reset / baseline ── */
  body, html { margin: 0; padding: 0; }
  body { background: #F4F6FA; font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #0B1A33; line-height: 1.55; -webkit-font-smoothing: antialiased; }
  table { border-collapse: collapse; }
  img { display: block; border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }

  /* ── Layout ── */
  .wrap { max-width: 600px; margin: 0 auto; padding: 24px 16px; }
  /* Bottom corners only: the navy header above supplies the top ones, and the two
     join into a single object rather than a box sitting inside a box. */
  .card { background: #FFFFFF; border-radius: 0 0 14px 14px; padding: 32px; box-shadow: 0 1px 4px rgba(8, 28, 65, .06); border: 1px solid #E4E8EF; border-top: 0; }

  /* ── Header ── */
  /* The navy cap. Full width, edge to edge — the logo sits ON the background the way
     it does on the splash screen, rather than on a panel floating over it. */
  .logo-band { background: #081C41; border-radius: 14px 14px 0 0; }
  .logo-img { display: block; margin: 0 auto; border: 0; }
  /* The wordmark is dark ink on transparent, so it needs something light behind it
     wherever it lands. Matches the card so it reads as part of the design. */
  /* The brand navy, carrying the reversed wordmark — the same treatment as the
     login banner. Dark in BOTH schemes, so there is no state where the logo and
     its background can disagree, and nothing a client's dark mode can invert into
     an unreadable pairing. */
  .logo-fallback { font-size: 22px; font-weight: 800; color: #081C41; letter-spacing: -0.5px; }

  /* ── Typography ── */
  h1 { font-size: 26px; line-height: 1.2; margin: 0 0 12px; color: #081C41; font-weight: 800; letter-spacing: -0.4px; }
  h2 { font-size: 17px; line-height: 1.3; margin: 24px 0 8px; color: #081C41; font-weight: 700; }
  p { margin: 0 0 14px; font-size: 15px; color: #2A3D5F; }
  strong { color: #0B1A33; }
  a { color: #2EA9AC; text-decoration: none; }

  /* ── Button (bulletproof-ish) ── */
  .btn { display: inline-block; background: #081C41; color: #FFFFFF !important; padding: 13px 28px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 15px; margin: 16px 0; letter-spacing: -0.005em; }
  .btn:hover { background: #142A57; }

  /* ── Credentials box ── */
  .creds { background: #EEF1F8; border-left: 3px solid #3BBBBE; padding: 16px 18px; border-radius: 6px; font-family: 'SF Mono', 'Menlo', 'Consolas', monospace; font-size: 14px; margin: 16px 0; color: #0B1A33; }
  .creds strong { color: #081C41; font-family: 'Plus Jakarta Sans', sans-serif; }

  /* ── Totals table ── */
  .totals { width: 100%; border-collapse: collapse; margin: 16px 0; }
  .totals td { padding: 10px 0; border-bottom: 1px solid #E4E8EF; color: #2A3D5F; font-size: 15px; }
  .totals .total-row td { padding-top: 14px; font-size: 18px; font-weight: 800; color: #081C41; border-bottom: none; }

  /* ── Footer ── */
  .footer { margin-top: 24px; padding-top: 16px; border-top: 1px solid #E4E8EF; color: #8693A8; font-size: 12px; line-height: 1.6; }
  .footer a { color: #2EA9AC; }
  .preheader { display: none; max-height: 0; overflow: hidden; mso-hide: all; visibility: hidden; opacity: 0; color: transparent; height: 0; width: 0; }

  /* ── Dark mode ── */
  @media (prefers-color-scheme: dark) {
    /* Only the area AROUND the card follows dark mode. The card itself stays a
       LIGHT "sheet" (white, dark text) so every content block — including the
       inline-styled critical-change and maintenance emails — keeps its intended
       contrast and is always readable, instead of dark text on a dark card. */
    body { background: #0A1224 !important; }
    .card { background: #FFFFFF !important; border-color: #E4E8EF !important; border-top: 0 !important; box-shadow: 0 1px 4px rgba(0, 0, 0, .3) !important; }
    h1, h2 { color: #081C41 !important; }
    p, .totals td { color: #2A3D5F !important; }
    strong, .totals .total-row td { color: #0B1A33 !important; }
    .creds { background: #EEF1F8 !important; color: #0B1A33 !important; }
    .creds strong { color: #081C41 !important; }
    .footer { border-top-color: #E4E8EF !important; color: #8693A8 !important; }
    .logo-fallback { color: #081C41 !important; }
    /* Without this the wordmark is navy on a #0A1224 body: only the teal "Trac"
       and the sparkles survive, which is exactly what was reported. */
    .logo-band { background: #081C41 !important; }
    a { color: #1F6FB2 !important; }
  }

  /* Outlook.com / Windows Mail tag what they have recoloured with these attributes.
     They do not honour prefers-color-scheme, so the sheet has to be restored here
     as well or the same logo vanishes again in exactly the same way. */
  [data-ogsc] .logo-band,
  [data-ogsb] .logo-band { background: #081C41 !important; }
  [data-ogsc] .card,
  [data-ogsb] .card { background: #FFFFFF !important; }
  [data-ogsc] h1, [data-ogsc] h2 { color: #081C41 !important; }
  [data-ogsc] p { color: #2A3D5F !important; }

  /* ── Mobile ── */
  @media (max-width: 480px) {
    .wrap { padding: 12px 8px; }
    .card { padding: 24px 18px; border-radius: 10px; }
    h1 { font-size: 22px; }
    .btn { display: block; text-align: center; padding: 14px 20px; }
  }
</style>
</head>
<body>
  @php
    // Embed the logo INLINE (cid:) for real Mailables so Outlook and other clients
    // render it even when external images are blocked for a not-yet-trusted sender.
    // Direct View::make() renders (e.g. CriticalNotifier) have no $message → fall
    // back to the hosted URL, which is fine for those internal alerts.
    // The wordmark drawn FOR navy — white "Kiddie", teal "Trac", teal swoosh — at
    // 701x275, so it stays crisp at header size. This is the login banner's asset.
    //
    // NOT logo-wordmark-white.png: that is a flat all-white silhouette that throws the
    // teal away. And NOT the dark-ink wordmark: Gmail's dark mode recolours
    // backgrounds but never image pixels, so navy ink stayed navy on a card Gmail had
    // just darkened — the logo nobody could see. On a band that is navy in both
    // schemes there is no state where the ink and its background disagree.
    $__logoSrc = 'https://app.kiddietrac.com/login-wordmark.png';
    // Deliberately NOT $message->embed(). A CID inline attachment does not reliably
    // survive the per-agency transports this platform routes mail through, and when
    // it does not the src points at a content ID that is not in the message — the
    // broken logo reported on this email. The hosted URL is the same file, always
    // reachable, and degrades to alt text rather than a broken-image icon.
    //
    // If inline embedding is ever wanted again, it has to be proven against an
    // agency using its OWN mail transport, not against the default one.
  @endphp
<span class="preheader">{{ $preheader ?? $title ?? 'A message from Kiddietrac' }}</span>
<div class="wrap">
{{-- The navy cap. A full-width table, not a div: Outlook's Word engine ignores
     border-radius and padding on a div, and the header would lose its background
     entirely — which is the whole point of it. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">
  <tr>
    <td bgcolor="#081C41" class="logo-band" style="background:#081C41;border-radius:14px 14px 0 0;padding:30px 24px;text-align:center;">
      {{-- Never trusts the variable: an empty src is a broken image in every client,
           and that is exactly how one shipped. --}}
      <img src="{{ $__logoSrc ?: 'https://app.kiddietrac.com/login-wordmark.png' }}" alt="KiddieTrac" width="280" class="logo-img"
           style="width:280px;max-width:78%;height:auto;display:block;margin:0 auto;border:0;outline:none;text-decoration:none;">
    </td>
  </tr>
</table>
<div class="card">


  {!! $slot ?? $content ?? '' !!}

  <div class="footer">
    <p style="margin:0 0 10px;color:inherit;font-size:12px;">This email was sent by <strong style="color:inherit;">KiddieTrac</strong> on behalf of {{ $centreName ?? $agencyName ?? 'your childcare centre' }}. Visit <a href="{{ $appUrl ?? 'https://app.kiddietrac.com' }}">app.kiddietrac.com</a> to manage your account.</p>
    <p style="margin:0 0 6px;">
      <a href="https://www.kiddietrac.com/privacy" style="margin-right:14px;">Privacy policy</a>
      <a href="https://www.kiddietrac.com/terms"   style="margin-right:14px;">Terms of service</a>
      <a href="mailto:info@kiddietrac.com">Contact us</a>
    </p>
    <p style="margin:0;color:#8693A8;font-size:11px;">
      &copy; 2021&ndash;{{ date('Y') }} KiddieTrac. All rights reserved.<br>
      Powered by KiddieTrac &mdash; The Smart Childcare Management Platform<br>
      <span style="color:#9AA6B8;">noreply@kiddietrac.com is not monitored — contact your site administrator or info@kiddietrac.com</span>
    </p>
  </div>
</div>
</div>
</body>
</html>
